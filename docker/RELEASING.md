# Publishing the Docker image (maintainers)

The image is built by [.github/workflows/docker.yml](../.github/workflows/docker.yml)
and published to `ghcr.io/fiforms/announcementslides` for `linux/amd64` and
`linux/arm64` (each built on a native runner, then merged into one multi-arch image).
Nothing is built on push or pull request; an image only appears when you ask for one.

| You do this | The image gets these tags | Used by |
|---|---|---|
| **Publish a GitHub Release** `v1.4.0` | `:1.4.0`, `:1.4`, `:latest` | everyone following the quick start in the README (`compose.yaml` uses `:latest`) |
| Publish a Release marked **pre-release** `v1.5.0-rc1` | `:1.5.0-rc1` only (`:latest` and `:1.5` do **not** move) | people testing a release candidate |
| **Run workflow** manually (Actions tab) | `:edge` | your own test/demo instance; nothing stable follows it |

## Cutting a release

1. Merge what should ship to `master` and check the tests pass.
2. On GitHub: **Releases → Draft a new release**, choose **Create new tag on publish**
   with a name like `v1.4.0` (the `v` is required; other tags are ignored), write the
   notes, and **Publish release**. For a candidate, tick **Set as a pre-release**.
3. The **Docker image (multi-arch)** workflow starts by itself. When it finishes
   (a few minutes), `docker compose pull && docker compose up -d` on any server
   following `:latest` upgrades it. The workflow's last step prints the published
   manifests.

Pushing a bare git tag does **not** publish an image; it has to be a Release.

## Trying unreleased work (`:edge`)

**Actions → Docker image (multi-arch) → Run workflow**, pick the branch, leave the tag
as `edge`, and run. To use it:

```bash
cd docker
APP_IMAGE=ghcr.io/fiforms/announcementslides:edge docker compose pull
APP_IMAGE=ghcr.io/fiforms/announcementslides:edge docker compose up -d
```

- Untick **push** for a build-only check that publishes nothing (it still builds both
  architectures, which uses runner minutes).
- Another tag name (say `pr-45`) can be typed instead of `edge` for a throwaway image.
  `latest` is refused: only a release may move it. Tags are limited to letters, digits,
  `_`, `.` and `-`.
- The image reports its version as `edge-<commit sha>`, or the release tag.

## First-time setup / checks

- **Package visibility:** the first push creates the GHCR package as private. Make it
  public once (GitHub → your profile or org → Packages → `announcementslides` → Package
  settings → Change visibility), or anonymous `docker compose up` fails with `denied`.
  Verify with a logged-out check:
  `DOCKER_CONFIG=$(mktemp -d) docker manifest inspect ghcr.io/fiforms/announcementslides:latest`.
- **Cost:** the repository is public, so GitHub's native ARM runners and the standard
  runners are free; there is no QEMU emulation, which is what made the old single-job
  build slow.
- **Local build:** to build the image from your checkout instead, see *Building locally*
  in [README.md](README.md).
