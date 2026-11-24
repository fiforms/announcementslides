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

## Sending from OBS

### Simplest: OBS streams only to the device

If OBS only needs to feed this device:

1. Set the device to **RIST Unicast Listener** mode (see below).
2. Copy the URL from the device's web settings page.
3. In OBS, open **Settings → Stream** and paste it as the streaming destination.
4. Click **Start Streaming**.

### Keep recording and web streaming available

Usually OBS is also recording or streaming to the web, and OBS only has one
stream output. To send to the device *as well*, use the free
[obs-multi-rtmp](https://github.com/sorayuki/obs-multi-rtmp) plugin, which adds
extra outputs that run alongside OBS's normal Stream and Record buttons.

1. Install the plugin, then open its **Multiple output** dock from OBS's **Docks** menu.
2. Add a new output target and set its **Protocol** to **SRT/RIST**.
3. On the **Service** tab, paste the device's URL.
4. Choose the video and audio settings on the same window, then click **OK**.
5. Start the target from the dock whenever you want the device to show the feed.

![The OBS multiple-output target window with the SRT/RIST protocol, a RIST URL, and video and audio encoder settings](media:obs-multi-output-settings.png)

This screenshot uses **RIST Unicast Caller** mode: OBS waits for connections
(`rist://@0.0.0.0:5000`) and each device connects out to it. The **Sender URL**
on the device's page shows the address the devices should call. The settings
shown are known to work: the x264 encoder with CBR rate control at 10000 kbps,
30 FPS, the *veryfast* preset, the *baseline* profile, no B-frames, and AAC
audio at 128 kbps. The password in the URL is an example; use your own
passphrase (see below), and use the same one on the sender and every device.

**Why Caller mode is usually the best fit for OBS:**

- One OBS output serves **any number of Slide Announcers** with no change to OBS
  when you add, replace or reset a device.
- It doesn't depend on each Slide Announcer keeping the same IP address or
  being reachable by name on your network.
- It needs one thing in return: the computer running OBS must keep a **stable
  address**, either a static (or reserved) IP address or a hostname that always
  resolves to it. If that address changes, update the Sender URL on the devices.

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
