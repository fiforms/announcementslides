---
title: LAN video receiver
summary: Send live video from OBS or another encoder on your network to a Slide Announcer.
group: For church leaders
order: 60
audience: managers
---

The LAN video receiver lets a video switcher or encoder on your church's network
(OBS, vMix and similar) send a live picture to a paired Slide Announcer over
**SRT** or **RIST**. It is **off by default**.

## Turn it on

On the device's page, tick **Enable the LAN video receiver**. The device
switches the receiver on within about a minute, and a change made on the device
itself also shows up here. A "waiting for the device" message means the device
has not checked in yet.

## Choose a mode

| Mode | Use it when |
| --- | --- |
| **SRT** | The device waits for your sender (OBS, vMix or an encoder) to connect to it. Paste the connect URL shown on the page into the sender. |
| **RIST Unicast Listener** | The device waits for the sender. Best when one sender feeds this one device. |
| **RIST Unicast Caller** | The device connects out to a sender that is waiting for receivers, so one sender can feed many devices. Playback starts by itself when the sender starts and ends when it stops. The page shows the sender URL to use. |
| **RIST multicast** | One sender feeds many devices on a wired network. The group, port and passphrase must match the sender's. |

## Passphrase

If you set a passphrase it must be **10–79 characters** with **no spaces**, and
use only plain letters, numbers and symbols (no accented characters). It must
match the sender's exactly. SRT and RIST Unicast share one passphrase.

## Delay and stuttering

The **SRT latency** and **RIST buffer** settings trade delay for smoothness.
Lower them for less delay on a clean wired network; raise them if the picture
stutters.
