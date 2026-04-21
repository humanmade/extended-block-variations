# Contributing

## Release Process

Use this process for every release:

1. Ensure the version number on `main` is updated to your target new version.
2. Merge `main` into the `release` branch.
3. Create the release tag for that version.
4. Push the tag and the `release` branch to `origin`.

### Example Commands

Replace `X.Y.Z` with the release version:

```bash
git checkout main
# Confirm version bump is present in plugin.php.

git checkout release
git merge --no-ff main

git tag vX.Y.Z

git push origin release
git push origin vX.Y.Z
```

## Notes

- Keep tags consistent and follow [semver](https://semver.org/).
- Prefix tags with `v`, _e.g._ `v1.0.0` not `1.0.0`.
