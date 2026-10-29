"""Validate the production model with disposable example files; never start services."""

import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile


def compose_command():
    docker = shutil.which("docker")
    if docker:
        result = subprocess.run(
            [docker, "compose", "version"], capture_output=True, text=True
        )
        if result.returncode == 0:
            return [docker, "compose"]
    # Docker Desktop may expose the plugin directly even without an active context.
    if os.name == "nt":
        plugin = (
            Path(os.environ.get("ProgramFiles", r"C:\Program Files"))
            / "Docker/Docker/resources/cli-plugins/docker-compose.exe"
        )
        if plugin.is_file():
            return [str(plugin)]
    raise RuntimeError("Install Docker Compose v2 to validate the production model.")


def main():
    root = Path(__file__).resolve().parent.parent
    command = compose_command()
    with tempfile.TemporaryDirectory(prefix="homologa-compose-") as directory:
        fixture = Path(directory)
        shutil.copyfile(root / "compose.production.yaml", fixture / "compose.production.yaml")
        for name in (".env.production", ".env.app", ".env.db", ".env.web"):
            shutil.copyfile(root / f"{name}.example", fixture / name)
        (fixture / "secrets").mkdir()
        for name in ("db_password", "redis_password", "resend_api_key"):
            (fixture / "secrets" / f"{name}.txt").write_text(
                "compose-validation-fixture", encoding="utf-8"
            )
        result = subprocess.run(
            command
            + [
                "--project-directory", str(fixture),
                "--env-file", str(fixture / ".env.production"),
                "-f", str(fixture / "compose.production.yaml"),
                "config", "--format", "json",
            ],
            capture_output=True,
            text=True,
            encoding="utf-8",
            check=True,
        )
        model = json.loads(result.stdout)

    services = model["services"]
    assert set(services) == {"app", "db", "redis", "caddy", "worker", "scheduler"}
    assert model["networks"]["data"]["internal"] is True
    for name, service in services.items():
        assert service["restart"] == "unless-stopped", name
        assert service.get("mem_limit") not in (None, 0, "0"), name
        assert service["logging"]["options"] == {"max-size": "10m", "max-file": "3"}, name
        assert "no-new-privileges:true" in service["security_opt"], name
        if name != "caddy":
            assert not service.get("ports"), f"Unexpected published port: {name}"
    assert {
        (str(port["published"]), port["target"], port["protocol"])
        for port in services["caddy"]["ports"]
    } == {("80", 80, "tcp"), ("443", 443, "tcp")}
    assert set(services["caddy"]["networks"]) == {"edge"}
    for name in ("db", "redis"):
        assert set(services[name]["networks"]) == {"data"}, name
    assert services["db"]["environment"]["POSTGRES_USER"] == "postgres"
    assert "-h 127.0.0.1" in services["db"]["healthcheck"]["test"][1]
    for name in ("app", "worker", "scheduler"):
        service = services[name]
        environment = service["environment"]
        assert set(service["networks"]) == {"edge", "data"}, name
        assert environment["APP_ENV"] == "production", name
        assert environment["APP_DEBUG"] == "false", name
        assert environment["FILESYSTEM_DISK"] == "local", name
        assert environment["SESSION_SECURE_COOKIE"] == "true", name
        assert environment["DB_USERNAME"] != "postgres", name
        assert "POSTGRES_PASSWORD" not in environment, name
        assert environment["DB_PASSWORD_FILE"] == "/run/secrets/db_password", name
        assert environment["REDIS_PASSWORD_FILE"] == "/run/secrets/redis_password", name
        assert environment["MAIL_MAILER"] == "resend", name
        assert environment["RESEND_API_KEY_FILE"] == "/run/secrets/resend_api_key", name
        assert "RESEND_API_KEY" not in environment, name
        assert {secret["source"] for secret in service["secrets"]} == {
            "db_password", "redis_password", "resend_api_key"
        }, name
        assert all(
            mount["type"] != "bind" or mount["target"] == "/var/www/html/storage"
            for mount in service["volumes"]
        ), f"Source-code bind mount in production: {name}"
    assert "--queue=mail,default" in services["worker"]["command"]
    assert "resend_api_key" not in {secret["source"] for secret in services["db"]["secrets"]}
    assert not services["caddy"].get("secrets")
    print("Production Compose passed: ports, networks, secrets, persistence and resource controls.")


if __name__ == "__main__":
    main()
