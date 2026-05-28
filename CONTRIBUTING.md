# Contributing

## Release Process

This plugin does not provide any frontend code, so no build step is required in order to publish.

Use this process for every release:

1. Use a PR to update the version number on `main` to your target new version.
2. Create the release tag for that version.
3. Push the tag to `origin`.

### Example Commands

Replace `X.Y.Z` with the release version:

```bash
git checkout main
# Confirm plugin is ready for release.
# Confirm version bump is present in plugin.php.

# Tag the release and push the tag.
git tag vX.Y.Z
git push origin vX.Y.Z
```

You may alternately skip the local tagging step and instead [Use the "tag release" action](https://github.com/humanmade/extended-block-variations/actions/workflows/tag-release.yml) to apply a version tag to the prepared `main` branch then automatically create a [Release](https://github.com/humanmade/extended-block-variations/releases) from that tag.

## Notes

- Keep tags consistent and follow [semver](https://semver.org/).
- Prefix tags with `v`, _e.g._ `v1.0.0` not `1.0.0`.
