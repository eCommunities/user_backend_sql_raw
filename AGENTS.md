# Agent Notes

## Release Policy

This project does not currently have access to a Nextcloud app signing key because
the project has not yet been transferred to the current maintainers in the
Nextcloud app ecosystem.

Until that transfer is complete, lack of a signing key is not a release blocker.
Do not stop release work solely because `appinfo/signature.json` cannot be
generated or refreshed.

When a user requests a release:

1. Prepare the release metadata, changelog, and release artifact normally.
2. Run the relevant validation and test suite.
3. Commit the release changes when requested.
4. Create the release tag when requested.
5. Push the branch and tag when requested.
6. Publish the release artifact normally when requested, even if it is unsigned.

Generated release artifacts should not include a stale copied
`appinfo/signature.json`. Leave signing as a future step after the Nextcloud app
ownership transfer is complete.
