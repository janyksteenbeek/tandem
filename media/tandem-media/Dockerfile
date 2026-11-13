# syntax=docker/dockerfile:1

# Tandem media: the LiveKit media server (SFU, signalling, built-in TURN) and the recorder
# in one static Go binary on a distroless base. No ffmpeg, no browser, no shell. Build from
# this directory:
#
#   docker build -t tandem-media media/tandem-media
#
# CI (.github/workflows/docker.yml) publishes it as ghcr.io/<owner>/<repo>-media for
# linux/amd64 and linux/arm64.
#
# The image also carries tandem-recorder, the recorder on its own, for installations that
# record from a separate host: override the entrypoint with /usr/local/bin/tandem-recorder.
#
# Base images are build arguments so a hardened mirror can be swapped in, as in the main image.
ARG GO_IMAGE=golang:1.27-bookworm
ARG RUNTIME_IMAGE=gcr.io/distroless/static-debian12

# The compiler always runs on the build machine's own architecture and cross-compiles for the
# target, so a multi-platform build needs no emulation.
FROM --platform=${BUILDPLATFORM} ${GO_IMAGE} AS build

WORKDIR /src

COPY go.mod go.sum ./
RUN --mount=type=cache,target=/go/pkg/mod \
    go mod download

COPY . .

ARG VERSION=dev
ARG TARGETOS
ARG TARGETARCH
RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    CGO_ENABLED=0 GOOS=${TARGETOS} GOARCH=${TARGETARCH} go build -trimpath \
      -ldflags "-s -w -X github.com/janyksteenbeek/tandem/media/tandem-media/internal/recorder.Version=${VERSION}" \
      -o /out/ ./cmd/tandem-media ./cmd/tandem-recorder \
    && mkdir -p /out/recordings

# distroless/static ships CA certificates (wss://, https:// webhooks, TURN over TLS) and nothing else.
FROM ${RUNTIME_IMAGE} AS runtime

# The recordings volume is shared with the Tandem application, which serves, exports and
# deletes the files. Run as the same uid/gid as the application (www-data is 33 in the
# FrankenPHP image) so both sides can manage what the other wrote.
ARG MEDIA_UID=33
ARG MEDIA_GID=33

COPY --from=build /out/tandem-media /out/tandem-recorder /usr/local/bin/
COPY --from=build --chown=${MEDIA_UID}:${MEDIA_GID} /out/recordings /recordings

# The media server reads the usual LiveKit YAML: mount a file and pass `--config <path>`, or
# put the YAML itself in LIVEKIT_CONFIG. Recording is off until RECORDER_SECRET is set.
ENV RECORDER_LISTEN=:7890 \
    RECORDINGS_DIR=/recordings

USER ${MEDIA_UID}:${MEDIA_GID}

# 7880 signalling (HTTP/WebSocket), 7881 WebRTC over TCP, 7882/udp WebRTC, 3478/udp TURN,
# 7890 recorder control API and /healthz (keep this one on the internal network).
EXPOSE 7880 7881 7882/udp 3478/udp 7890
VOLUME ["/recordings"]

# There is no shell or curl in the image; the binary probes its own /healthz, which fails
# when either the media server or the recorder is unhealthy.
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD ["/usr/local/bin/tandem-media", "-healthcheck"]

# SIGTERM finalises every open recording and reports it before the media server stops; that
# can take a moment with long recordings, so give the container a stop grace period of 30s.
STOPSIGNAL SIGTERM

ENTRYPOINT ["/usr/local/bin/tandem-media"]
