# Imagens oficiais indisponíveis: compile as releases do código oficial.
FROM golang:1.25-alpine AS builder
RUN apk add --no-cache git ca-certificates
ENV CGO_ENABLED=0 GOTOOLCHAIN=local

FROM builder AS mc-build
ARG MC_RELEASE=RELEASE.2025-08-13T08-35-41Z
RUN git clone --depth 1 --branch "${MC_RELEASE}" https://github.com/minio/mc.git /src/mc
WORKDIR /src/mc
RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    go build -p 2 -trimpath -ldflags "$(MC_RELEASE=RELEASE go run buildscripts/gen-ldflags.go)" -o /out/mc .

FROM builder AS minio-build
ARG MINIO_RELEASE=RELEASE.2025-10-15T17-29-55Z
RUN git clone --depth 1 --branch "${MINIO_RELEASE}" https://github.com/minio/minio.git /src/minio
WORKDIR /src/minio
RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    go build -p 2 -trimpath -ldflags "$(MINIO_RELEASE=RELEASE go run buildscripts/gen-ldflags.go)" -o /out/minio .

FROM alpine:3.22 AS runtime
RUN apk add --no-cache ca-certificates tzdata
COPY --from=mc-build /out/mc /usr/bin/mc
COPY --from=mc-build /src/mc/LICENSE /usr/share/licenses/mc/LICENSE

FROM runtime AS client
ENTRYPOINT ["mc"]

FROM runtime AS server
COPY --from=minio-build /out/minio /usr/bin/minio
COPY --from=minio-build /src/minio/LICENSE /usr/share/licenses/minio/LICENSE
EXPOSE 9000 9001
ENTRYPOINT ["minio"]
CMD ["server", "/data", "--console-address", ":9001"]
