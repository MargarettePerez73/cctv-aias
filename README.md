# CCTV Media Management System

Surveillance dashboard for a community pharmacy — PHP 8 + MySQL on XAMPP, no build step.
Phase 1 uses USB and laptop webcams.

## Setup (XAMPP)

1. Copy this folder into `C:\xampp1\htdocs\cctv` (any folder name works).
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. New install only: open http://localhost/phpmyadmin → **Import** → `database/schema.sql`.
   An existing `cctv_media` database is upgraded automatically; nothing to import.
4. Open http://localhost/cctv/. On a new install the first screen creates the administrator.

Database credentials default to XAMPP's `root` with no password. To change them, copy
`app/config.example.php` to `app/config.php` and edit it.

Quick alternative without Apache: `php -S localhost:8000 router.php` in this folder, then open
http://localhost:8000 (MySQL still needs to be running).

## Using the webcams

1. On the PC with the USB / laptop webcams: **System settings → Detect webcams**, pick a webcam for each
   camera slot and save. Turn on **Continuous recording** for the slots you want recorded.
2. Open **Live cameras → Use this computer as station** and leave that tab open. It streams the cameras,
   detects motion / tampering, records, sends alerts and relays pictures to other devices.
3. Everyone else (owner, guard, staff on phones or other PCs) signs in normally and sees the relayed feeds.

Browsers only allow camera access on `http://localhost` or HTTPS.

## Detection

| Detects | How |
| --- | --- |
| Person | Pretrained COCO-SSD model (TensorFlow.js) in the station browser. No training or labeled video needed. Downloads about 5 MB from the internet the first time. |
| Fire | Large bright flame-coloured areas (turn on in System settings; experimental) |
| Motion | Pixel change between frames, sensitivity 1–10 |
| Tampering | Lens covered, room dark or camera unplugged |

Person and motion alerts follow **Motion & person alerts** in System settings. Set it to *Only outside business
hours* to arm them after closing time. Each detection saves a snapshot (people are outlined in red) and a short
event clip, and alerts every active owner, guard and administrator within a few seconds.

**Demo test (diorama):** set closing time to a minute ago, then put a person figure or photo, or a flame
picture, in front of the storage-room webcam. A Person detected or Possible fire event appears in
Security events with the date, time and snapshot, and every signed-in device shows the alert.

## Cloud copy

Set `cloud_sync_path` in `app/config.php` to a folder that Google Drive or OneDrive for desktop syncs, for
example `'G:/My Drive/CCTV'`. Every snapshot and recording is also copied there into a folder per day, named by
date and time (`2026-10-04/2026-10-04_21-30-05_storage-room_person.jpg`). The event page shows whether the copy
succeeded.

## Roles

| Role | Can do |
| --- | --- |
| Owner | Alerts, live feeds, review events, play + download recordings, audit logs, view users/settings |
| Administrator | Everything: users, RFID cards, cameras, settings, delete recordings and event clips |
| Guard | Alerts, live feeds, review events, play recordings (no download/delete) |
| Staff | Live feeds, door terminal, panic alarm. No events, recordings or downloads |

## RFID door terminal

Plug a USB RFID reader into the PC by the stock room and open **Door terminal**. The reader types the card
number and presses Enter; the scan is checked against cards issued in **Access control → RFID cards**.
Denied scans alert the owner and guard.

## Storage and retention

Recordings are kept in `storage/` and only served through PHP, so every playback and download is
permission-checked and written to the audit log. Footage older than the retention period (30 days by
default) is deleted daily; event clips are kept as evidence. PHP's `upload_max_filesize` /
`post_max_size` (40M in XAMPP) cap each upload — the station sizes its recording segments to fit.

## Not in Phase 1

Network IP cameras / NVR, night-vision (infrared) cameras, SMS or push delivery through a provider, encrypted
cloud backup, and wired hardware (siren relay, smoke detectors, UPS, electric lock). Alerts are delivered in-app, by browser
notification and by sound.
