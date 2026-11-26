# Tandem Calls

Video meetings inside Tandem: instant meetings with a shareable link, guests without an account,
screen sharing, server-side recording, persistent chat with files, and a history with playback
and an emailed transcript afterwards. Everything is self-hosted; no paid service or cloud API is
involved anywhere.

The priorities, in order, are audio, reliability, screen sharing, and then camera video. When
something has to give, the camera gives first.

## What runs

```
 Browser ──HTTPS──► Tandem (Laravel)          who may join, tokens, chat, files, history, email
    │  ▲                │        ▲
    │  └──WebSocket─────┘        │ signed webhooks (room events, recording reports)
    │      (Reverb: chat,        │
    │       participants)        │ HTTP on a private port (start/stop recording, room API)
    │                            ▼
    └──WebRTC (UDP/TCP)──► tandem-media        one Go process
                             ├─ LiveKit SFU    forwards encrypted media, never decodes it
                             └─ recorder       writes each received track to its own WebM file
                                   │
                                   ▼
                          storage/app/private/calls/recordings/   (shared with Laravel)
```

The media side is a **single binary**, `tandem-media` (`media/tandem-media`). It embeds the
open-source LiveKit server (Apache-2.0, v1.13.11) as a library and runs the recorder in the same
process. There is no second media service, no headless browser, no GStreamer and no GPU.
FFmpeg exists only in the application image and only runs on the queue, after a call, when
someone asks for a single-file download.

### Why this stack

| Option                       | Verdict                                                                                                                                                                 |
| ---------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **LiveKit, self-hosted**     | Chosen. One Go binary, about 45 MB idle, simulcast, dynacast, adaptive stream, active speakers, reconnection and a built-in TURN server. Mature browser SDK.            |
| LiveKit Egress for recording | Rejected. Its room recording renders the meeting in headless Chrome and encodes with GStreamer: hundreds of MB and a busy core per recording, the opposite of the goal. |
| A custom Pion SFU            | Rejected. It would mean rebuilding congestion control, simulcast layer selection and reconnection that LiveKit already does well.                                       |
| mediasoup / Janus            | Workable SFUs, but a Node/C++ or C runtime plus separate signalling to write, and no lighter than LiveKit for small meetings.                                           |

The recorder is the one custom media component, because nothing light existed: it joins a room
as a hidden participant (LiveKit's own Go SDK, Pion underneath) and stores what it receives.

## How a call works

### Joining

1. `/meet/{code}` is public. A signed-in member of the call's organization walks in; anyone
   else is a guest who gives a name and, by default, waits until a host admits them
   (`CallActions::join`). A password, a lock and a personal email invitation change that in the
   obvious ways.
2. Once admitted, the browser asks `POST /meet/{code}/credentials` for a media token. Laravel
   signs a JWT (HS256, the media server's key pair) that is valid for 10 minutes, names exactly
   one room, and lists the sources this participant may publish (screen sharing can be reserved
   for hosts). The token is requested again on every reconnect and never stored.
3. The browser opens a WebSocket to the SFU (signalling) and two peer connections (publish and
   subscribe). Media flows over UDP port 7882; if UDP is blocked, over TCP 7881; if the network
   only allows relaying, through TURN (see below).

Guests have no Tandem user. They are a `call_participants` row remembered by their session, and
every in-call endpoint is authorised per participant (`EnsureCallParticipant`,
`ParticipantAbilities`). A guest session opens that one call and nothing else: not the
organization, not another meeting, not another realtime channel.

### How the SFU routes media

The SFU terminates each participant's encrypted connection and forwards RTP packets to the
others. It does not decode, mix or transcode, which is why it is cheap.

- **Cameras** are published as VP8 simulcast (three layers, up to 720p; 1080p under "High"). The
  SFU sends each viewer the layer that fits the size the video is shown at and the bandwidth
  that viewer has (adaptive stream), and tells publishers to stop encoding layers nobody watches
  (dynacast).
- **Screen shares** are VP9 with one spatial layer and three temporal layers (`L1T3`), captured
  at the display's own resolution up to 4K, at 15 fps (30 in "optimise for motion"). A viewer on
  a weak link receives fewer frames of the same sharp picture, never a downscaled one. Screen
  sharing is independent of the camera quality setting.
- **Audio** is Opus at 48 kbit/s, fullband, with redundancy (RED) against packet loss and
  without DTX. Echo cancellation, gain control and noise suppression are the browser's own.

Audio is never reduced. Under congestion the SFU's allocator drops camera layers first, then
pauses camera streams; the room also offers an explicit "audio only" mode and suggests it after
several seconds of poor connection quality.

Each listener can turn any other participant down, or silence them, for themselves: on the
person's tile or in the people panel. That is the volume of the audio element in that one
browser (`TrackAudio.vue`, `useParticipantVolumes`); the SFU, the other participants and the
recording never see it. One level covers a person's voice and the sound of a screen they share,
and it is remembered for that call in that tab. iOS does not let a page set an element's volume,
so there the control is mute only.

The meeting can float in a picture-in-picture window (the menu, or Shift P) so it stays in view
while its tab is in the background. In Chromium that is a document picture-in-picture window
(`MeetPip.vue`, rendered into it with a `<Teleport>`): the shared screen or up to four people,
with microphone, camera and leave buttons. It attaches its own video elements to the same
tracks. Safari can only float a single video, so there the shared screen or the first camera
floats on its own; Firefox offers neither to a page and the menu item is hidden.

Rooms only allow Opus, VP8 and VP9 (`room.enabled_codecs`). That restriction is deliberate:
every track then fits a WebM file exactly as it arrives.

### Camera effects

A participant can put an effect on their camera, before joining or from Settings → Effects. The
first one is **Egg**: a plate of scrambled egg fills the picture and the person's own eyes and
mouth sit on it like a face, moving as they do.

An effect is made entirely in the sender's browser (`resources/js/lib/meet/effects`):

1. A face tracker finds the face in each camera frame. It is MediaPipe's face landmarker
   (Apache-2.0) running as WebAssembly in the page. Its runtime comes from the
   `@mediapipe/tasks-vision` package (a 14.7 MB WebAssembly file, 4.2 MB gzipped) and its
   model (`assets/face_landmarker.task`, 3.8 MB) is a file of this repository; both are built
   into the application's assets and served by Tandem. No frame and no measurement is sent
   anywhere, and none of it is downloaded by someone who never picks an effect.
2. The eyes and the mouth are cut out of the camera frame with soft edges and drawn onto the
   picture: scaled so the face has the same size however far away someone sits, tilted with
   the head, and drifting a little with it (`geometry.ts`, `facePicture.ts`).
3. The canvas is captured as a video track and published in place of the camera
   (`processor.ts`, through the SDK's track processor hook).

So the SFU, the other participants and a recording only ever get the finished picture. The
camera frame as it is never leaves the page while an effect is on: the effect is attached to
the camera track before the track is published, and until the tracker has loaded, when it finds
no face and when it fails to start, the output is the picture alone. A metronome in a worker
keeps frames coming while the tab is in the background, where browsers stop a page's own
timers.

As published, the MediaPipe package reports usage and performance metrics to Google
(`odml.pa.googleapis.com`) from every browser that runs it, with no setting to switch that off.
The build takes that address out (`withoutMediapipeMetrics` in `vite.config.ts`): the reporter's
first attempt fails without a network request and it stops for good. The build fails if the
address is no longer found, so an upgrade of the package cannot bring the reporting back
unnoticed. Measured in headless Chromium over 75 seconds (the reporter fires every 60): two
requests to Google without the change, none with it.

An effect costs the sender one face-tracking pass per camera frame (on the graphics card where
there is one) and nothing on the server. Adding an effect is a picture, a thumbnail and a
layout (where the eyes go and how far apart) in `catalog.ts`.

### TURN fallback

Most participants connect directly (UDP 7882). The fallbacks, in the order ICE tries them:

1. **ICE over TCP** on 7881, for networks that block UDP.
2. **The built-in TURN relay** on UDP 3478 (enabled in `compose.yaml`), for clients behind
   symmetric NAT.
3. **TURN over TLS on 443**, for networks that allow nothing else. This needs a certificate:
   either give LiveKit's TURN a `domain` and `tls_port`, or run the optional coturn service
   (`media/coturn/turnserver.conf`, `docker compose --profile coturn up`) and list it under
   `rtc.turn_servers`.

Only the direct route was exercised in testing; see "Not verified".

### Realtime application state

Chat, the participant list, the waiting room and the recording state are application data, not
media. They live in MySQL and are announced over Reverb on the private channel `call.{uuid}`
(`CallUpdated`, `CallParticipantUpdated`, `CallMessageChanged`). Guests authorise that channel
through a dedicated `call-guest` guard that only ever yields their own participant for their own
call. If Reverb is unreachable the room polls instead, and after any reconnect it re-reads the
room state and every chat message after the last one it has.

The media server reports room events back (`POST /webhooks/calls/media`, verified with the
server's key pair). `calls:sync` runs every minute as the safety net: it ends calls whose room is
gone, corrects presence, and catches recordings that stopped reporting.

## Recording

All recording happens on the server. Nothing depends on anyone's browser staying open.

### How tracks are recorded

Starting a recording (`POST /meet/{code}/recordings`) creates a `call_recordings` row and asks
the recorder to join the room. The recorder mints its own hidden, subscribe-only token, subscribes
to the tracks the chosen mode includes, and writes **one WebM file per track**:

| Mode             | Captures                                              |
| ---------------- | ----------------------------------------------------- |
| Full meeting     | every microphone, camera, shared screen and its audio |
| Screen and audio | shared screens and all audio, no cameras              |
| Audio only       | microphones and shared-screen audio                   |

Per track the recorder reassembles RTP packets into frames and appends them to the file. Video is
never decoded or re-encoded; the bytes on disk are the bytes the browser's encoder produced, at
the highest quality layer. A 1080p screen share is recorded as 1080p regardless of how small the
cameras are. Audio gaps (packet loss, a muted microphone) are filled with Opus silence frames so
every audio file has a continuous timeline.

### How recordings stay synchronised

Inside a file, timestamps are pure RTP time, so a track never drifts against itself. Each
track's position on the shared recording timeline (`start_ms` in the manifest) comes from RTCP
sender reports, which map RTP time to the SFU's clock; arrival time is only the fallback when a
sender report is implausible. Measured with a beep and a flash generated from one clock, audio
and video landed within 2 to 29 ms of each other on the shared timeline.

`manifest.json` lists every file with its participant, source, codec, start offset, duration and
size, plus events (joins, leaves, mutes, active speaker). Laravel stores the manifest on the
recording row. The player (`RecordingPlayer.vue`) plays the per-track files against one master
clock, each `<audio>`/`<video>` element active only during its own interval and kept within
about 120 ms of the clock (measured median 11 to 28 ms).

### How files are finalised

Files are written incrementally and synced to disk every 2 seconds. On stop, each file gets its
duration and a seek index (Cues) written into space reserved at the start, so it seeks instantly
in a browser without a second pass over the data. The manifest is rewritten atomically whenever
a track starts or ends, so there is always a valid one on disk.

| Event                                                      | Outcome                                                                                                                                                                                  |
| ---------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Stop requested, room closed, 6 h limit, disk below 512 MiB | files finalised, `recording.finished`                                                                                                                                                    |
| `tandem-media` stopped gracefully (SIGTERM)                | every open file is finalised and reported **before** the SFU stops; status `ready`                                                                                                       |
| `tandem-media` killed (`kill -9`, power loss)              | on the next start the partial files are repaired in place (truncated to the last whole frame, index and duration written) and reported as `partial`. Measured loss: 0.5 to 1.9 s of tail |
| A panic inside the recorder                                | contained: that recording is reported `failed` with whatever reached disk, the SFU and the calls keep running                                                                            |
| The recorder cannot be reached when starting               | the start is refused with the reason; no recording indicator is shown                                                                                                                    |

The recorder reports through signed webhooks (`POST /webhooks/calls/recorder`, the same
timestamped HMAC as the other Tandem webhooks): started, a progress heartbeat every 15 s,
warnings, finished, failed. A recording never fails silently: warnings and failures are stored on
the row, broadcast to the room, and a failure or partial result is sent to the host as a
notification. A recording whose heartbeat stops is flagged within 90 s and failed after 15
minutes if the recorder never returns.

### Consent

When a recording starts, or when someone joins while one is running, the room shows a blocking
notice with two choices: stay or leave. Staying is stored (`recording_consent_at`) and audited.
The recording indicator is visible to everyone for as long as it runs. Only hosts can record by
default; a host can extend that to members; guests never can. There is no way to start the
recorder that bypasses this: its API needs a secret only the application holds.

### Playback, downloads and exports

- **Playback** is synchronised multi-track playback of the original files, in the history and on
  the public share page.
- **Per-track downloads** are the original files, untouched.
- **Exports** are built on request by `BuildRecordingExport` on a dedicated single-worker, low
  priority, long-timeout queue (`calls-media` on the `redis-long` connection), so encoding never
  competes with live calls:

| Export                     | What it is                                                                                 | Cost                                                                 |
| -------------------------- | ------------------------------------------------------------------------------------------ | -------------------------------------------------------------------- |
| Audio only (`.m4a`)        | every voice mixed at its place on the timeline, AAC 160 kbit/s                             | audio encode only                                                    |
| Screen recording (`.webm`) | the shared screen **stream-copied** (same pixels as recorded) with the mixed audio in Opus | audio encode only                                                    |
| Combined video (`.mp4`)    | the screen (or a camera grid) with camera thumbnails, H.264 + AAC                          | a full video encode; slow on a small VPS, but it runs after the call |

On this machine the three exports of a 35 s, six-track recording took 4, 4 and 8 seconds.
Without FFmpeg, playback and per-track downloads keep working and the export menu says why it
is unavailable.

### Who may see a recording

- **Members**: the host, organization members who were admitted to the call, and organization
  administrators (`CallPolicy`). Every stream request is checked, and only file names listed in
  the manifest are ever opened.
- **A secret link** a member creates explicitly ("Copy secure link"). The token is stored
  hashed, expires after 30 days, can be revoked, and shows one recording and nothing else.
- **A signed link in a transcript email**, valid for 14 days.

Track streams on the two public routes are themselves signed, expiring URLs. Retention is per
organization (or `CALLS_RECORDING_RETENTION_DAYS`); `calls:prune-recordings` deletes expired
recordings with their files daily. Deleting a recording or a call removes the files.

## Chat, files and the transcript

Chat messages are rows in `call_messages`, plain text only: nothing a participant types is ever
interpreted as markup, in the app or in email. Sending is optimistic with a client id that makes
retries idempotent. Files go to the private attachments disk with the same blocked extensions
as mail attachments and are only served through an authorising controller or a signed link.
Chat stays readable after the meeting and becomes read-only when it ends.

**Email Meeting Chat** sends an HTML and plain-text transcript (title, date, duration,
participants, the chat in order, file links, recording links). Each recipient is a tracked
delivery that can be resent. Mail goes through the organization's own SMTP server when one is
configured, otherwise the application's outbound mailer. Automatic sending when a meeting ends
is off unless the host or the organization turns it on, and then only goes to people who were
actually in the meeting.

## Running it

### Local development

Build the media binary once (Go 1.26+):

```bash
cd media/tandem-media
go build -o ../bin/tandem-media ./cmd/tandem-media
```

Add to `.env` (the values match `media/livekit/livekit.dev.yaml`):

```
LIVEKIT_URL=http://127.0.0.1:7980
LIVEKIT_PUBLIC_URL=ws://127.0.0.1:7980
LIVEKIT_API_KEY=tandemdev
LIVEKIT_API_SECRET=tandem-local-development-secret-0123456789
CALLS_RECORDER_URL=http://127.0.0.1:7890
CALLS_RECORDER_SECRET=<any long random string>
```

Then, next to `php artisan serve`, `reverb:start` and `horizon`:

```bash
RECORDER_LISTEN=127.0.0.1:7890 \
RECORDER_SECRET=<the same CALLS_RECORDER_SECRET> \
RECORDINGS_DIR="$PWD/storage/app/private/calls/recordings" \
RECORDER_WEBHOOK_URL=http://127.0.0.1:8000/webhooks/calls/recorder \
media/bin/tandem-media --config media/livekit/livekit.dev.yaml
```

Browsers only allow camera and microphone on `localhost` or HTTPS, so testing from a second
device needs TLS in front and `rtc.node_ip` set to the machine's LAN address.

### Docker Compose

`compose.yaml` adds one service, `media`, built from `media/tandem-media/Dockerfile` (static
binary on a distroless base, runs as uid 33 like the application). CI publishes the same image
as `ghcr.io/<owner>/<repo>-media` for `linux/amd64` and `linux/arm64`; set `TANDEM_MEDIA_IMAGE`
to use it. `docs/deployment.md` has the step-by-step checklist for a server, with and without
Docker. Set in `.env`:

```
LIVEKIT_API_KEY=<name>
LIVEKIT_API_SECRET=<at least 32 random characters>
LIVEKIT_PUBLIC_URL=wss://meet.example.com
CALLS_RECORDER_SECRET=<random>          # leave empty to run calls without recording
```

`docker compose --profile tls up -d` adds Caddy with automatic certificates for `APP_DOMAIN` and
`MEET_DOMAIN` (`media/caddy/Caddyfile`). Open these ports on the host firewall:

| Port     | Purpose                                                      |
| -------- | ------------------------------------------------------------ |
| 443/tcp  | the app, Reverb and WebRTC signalling, through the TLS proxy |
| 7882/udp | media (all participants share this one port)                 |
| 7881/tcp | media fallback when UDP is blocked                           |
| 3478/udp | built-in TURN relay                                          |

Port 7880 (signalling without TLS) is bound to loopback and 7890 (recorder API) is not published
at all. The media container has a memory limit (512 MiB by default), bounded logs, a health check
that fails if either the SFU or the recorder is unhealthy, and a 30 s stop grace period so
recordings are closed cleanly.

### Standalone binary

`tandem-media --config /etc/tandem/livekit.yaml` with the recorder variables above in the
environment is the whole media deployment; `media/livekit/livekit.yaml` is the annotated
production configuration. Cross-compiled static Linux builds are about 55 MB.

| Variable                      | Meaning                                                                               |
| ----------------------------- | ------------------------------------------------------------------------------------- |
| `LIVEKIT_CONFIG` / `--config` | the SFU configuration (upstream LiveKit YAML)                                         |
| `RECORDER_SECRET`             | shared with `CALLS_RECORDER_SECRET`; unset disables recording, calls still work       |
| `RECORDINGS_DIR`              | where track files are written; must be the application's `calls/recordings` directory |
| `RECORDER_WEBHOOK_URL`        | `https://app.example.com/webhooks/calls/recorder`                                     |
| `RECORDER_LISTEN`             | recorder API address, default `:7890`; keep it private                                |
| `RECORDER_MIN_FREE_BYTES`     | refuse and stop recordings below this much free disk (512 MiB)                        |
| `RECORDER_MAX_DURATION`       | safety stop, default 6 h                                                              |

Application settings are in `config/calls.php` (`CALLS_MAX_PARTICIPANTS`, token lifetime, upload
limit, retention, FFmpeg binary).

One deployment rule: the recorder connects to the SFU like any other participant, so **the host
must be able to reach the address the SFU advertises**. That is true for a VPS with a public
address on its interface and for Docker's published ports. On a cloud that maps a public address
onto a private one without hairpinning, set `rtc.node_ip` accordingly or recording will fail to
connect while calls still work.

## Measured resource use

Measured on an Apple M2 laptop with the SFU, the recorder and every participant on the same
machine: headless Chromium with its synthetic camera and microphone, joining through the real
UI. CPU is the media process's CPU time over 30 s, as a share of one M2 core. **These are not
VPS numbers**; no VPS was tested.

| Scenario                                | Media process RSS | CPU (one M2 core) | Per-participant traffic                                      |
| --------------------------------------- | ----------------- | ----------------- | ------------------------------------------------------------ |
| Idle, fresh start                       | 43 MiB            | 0.1 %             |                                                              |
| 1:1, audio only                         | 85 MiB            | 1.5 %             | 75 kbit/s each way                                           |
| 1:1, audio only, recorded               | 98 MiB            | 2.1 %             | 75 kbit/s each way                                           |
| 1:1 video (720p)                        | 95 MiB (max 101)  | 2.9 %             | 1.3 Mbit/s up (three simulcast layers), 0.8 Mbit/s down      |
| 4 people, video (720p grid)             | 87 MiB (max 99)   | 10.5 %            | 1.3 Mbit/s up, 2.3 Mbit/s down                               |
| 2 people + 1080p screen share           | 121 MiB           | 2.9 %             | sharer 1.6 Mbit/s up; viewer 1.3 Mbit/s screen + 0.15 camera |
| 2 people + 1080p screen, full recording | 129 MiB (max 132) | 7.1 %             | as above; cameras stay at 720p up for the recorder           |
| 4 people + 1080p screen, full recording | 135 MiB (max 142) | 15.1 %            | viewer 1.3 Mbit/s screen + three small cameras               |

Audio in every run: 0 packets lost, jitter at or below 1 ms (loopback network). The process does
not hand memory back quickly: after these runs it idled at 121 MiB.

Recording disk use, from the files written:

| Recording                                          | Disk                                 |
| -------------------------------------------------- | ------------------------------------ |
| Audio, per microphone                              | about 0.4 MiB per minute             |
| 2 people (720p cameras) + 1080p screen share, full | 20 MiB per minute (1.2 GiB per hour) |
| 4 people + 1080p screen share, full                | 30 MiB per minute (1.8 GiB per hour) |

The synthetic camera and a mostly static shared tab compress very well; real cameras and busy
screens will use more bandwidth and disk than this.

### What that means for a small VPS

This is an estimate from the numbers above, not a measurement.

- **Memory** is not the constraint: the media process stayed under 150 MiB in every scenario.
  The existing Tandem stack (PHP, MariaDB, Redis, Reverb, Horizon) needs far more than that, so
  a 1 GB machine is realistic for the media server alone, or for a small all-in-one install with
  swap; 2 GB is comfortable for everything on one host.
- **CPU**: a shared vCPU is commonly two to four times slower than an M2 core. The 4-person
  recorded call (15 % of an M2 core) would then use roughly a third to two thirds of one vCPU,
  with headroom for one such meeting at a time and little else.
- **Bandwidth** is the real limit. A 4-person 720p grid has the server sending about 9 Mbit/s
  and receiving about 5; check the provider's transfer allowance.
- **Disk** fills at 1 to 2 GiB per recorded hour with video. Set a retention period.
- **Combined video exports** are a full H.264 encode and will take longer than the meeting did
  on one slow vCPU. Audio and screen exports only encode audio and are quick everywhere.

If the machine cannot keep up, lower `CALLS_MAX_PARTICIPANTS`, record "screen and audio" or
"audio only" instead of the full meeting, or move the media server to its own host. Audio
bitrate is not a tuning knob here and is never lowered to save resources.

## Scaling beyond one VPS

- **Move media off the app host.** `tandem-media` only needs the recordings directory in common
  with Laravel (a shared volume, or NFS).
- **Several media nodes.** LiveKit clusters through Redis (`redis:` in its YAML); a room lives
  on one node. Each node runs its own recorder, so Laravel would have to send the start request
  to the node that hosts the room. That routing is not built: today `CALLS_RECORDER_URL` is one
  address.
- **A separate recorder.** The module also builds `tandem-recorder` (the same recorder without
  the SFU linked in), for running recording on a different host than the SFU.
- **Object storage.** Chat files already follow `TANDEM_ATTACHMENTS_DISK` (S3-compatible, for
  example MinIO). Recordings are written to a local path; `RecordingPlayback` can serve from an
  object store through temporary URLs, but moving finished recordings there is not built, and
  exports need the files locally.

## Tests

- `tests/Feature/Calls`: 130 Pest tests for joining and guest isolation, host controls, chat and
  files, the recording lifecycle and its webhooks, sharing and signed links, exports, transcripts
  and email, invitations, realtime channel authorisation, the token signer and the FFmpeg
  commands.
- `media/tandem-media`: Go unit tests (`go test -race ./...`) for the WebM writer and repair,
  frame assembly, silence filling, manifest writes, the API and the webhook signature, which is
  pinned to the same test vector as the PHP side. CI runs them with `gofmt` and `go vet` in the
  `media` job of `.github/workflows/tests.yml`.
- `resources/js/lib/{meet,calls}`: Vitest for layout, linkify, optimistic chat merge, device
  fallback, the player's timeline and drift maths, and the geometry of camera effects (where
  the eyes and mouth land, at which size, and how their cut-outs behave).
- `tests/e2e/calls.spec.ts`: a real two-browser meeting (host and guest, fake camera and
  microphone) with chat, a server-side recording, and playback of the recorded tracks. It needs
  the full local stack including `tandem-media`.

## Not verified

Stated plainly, because none of this should be assumed to work until it has been tried:

- **No real VPS and no Docker.** Docker is not installed on the development machine, so the
  `media` image, the application image change (static FFmpeg) and the compose stack were never
  built or run there; the images are only built by CI. The binaries were cross-compiled for
  `linux/amd64` and `linux/arm64` with the Dockerfile's build command, and the generated LiveKit
  configuration from `compose.yaml` was started with the real binary outside Docker. The systemd
  unit in `docs/deployment.md` is an untested example.
- **Only Chromium, only synthetic devices.** No real camera, microphone or speaker; no Safari
  or Firefox, in the room or in the player; no phone.
- **Per-participant volume and picture-in-picture were never run.** Both were written without
  a browser or the test suite at hand. For picture-in-picture in particular: that the video in
  the floating window keeps flowing once the meeting's tab is hidden depends on the SDK's
  adaptive stream recognising a document picture-in-picture window, which was not checked.
- **Camera effects were never run on a real face or in a meeting.** What was run is the built
  pipeline in headless Chromium against a drawn face on a canvas standing in for the camera:
  the tracker loaded from Tandem's own assets, found the face, the eyes and mouth landed on the
  picture, the output ran at 30 frames per second, stopped while the camera track was stopped
  and resumed on a new one. Not run: a person in front of a real camera, an effect published
  into a room (that the SDK attaches it before publishing was read in its source, not
  observed), a recording of one, a tab in the background, Safari, Firefox and phones.
- **No lossy or constrained network.** Loss concealment, the audio-first degradation and the
  "audio only" suggestion were not exercised under real packet loss or bandwidth limits.
- **No TURN.** Only direct connections were available; TCP fallback, the built-in relay and
  coturn are configured but untested.
- **No long recordings.** The longest real recording was under a minute. Multi-hour behaviour
  (index thinning, RTP timestamp wrap) is unit-tested only. Disk filling up mid-recording was
  not exercised, only the refusal to start.
- **Recorder odds and ends.** A camera muted mid-recording was not tested. FFmpeg reported two
  duplicate frame timestamps in one camera file out of several; the file decoded and played.
- **Screen share against audio sync** was measured for cameras only.

## Known limitations

- Restarting `tandem-media` ends the calls on it. Recordings are closed cleanly first.
- An ended meeting cannot be reopened; its link shows that it has ended.
- Recording keeps every publisher's highest camera layer alive, so a recorded meeting uses more
  upstream bandwidth than an unrecorded one.
- Clock drift between participants is measured and stored per track, not corrected.
- Invitations from inside the room take email addresses; there is no member picker.
- A meeting cannot be started from a mail conversation directly; the composer can insert a
  meeting link.
- One screen share per person at a time.
