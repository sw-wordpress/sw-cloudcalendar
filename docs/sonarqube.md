# SonarQube Cloud

The test workflow uploads merged PHP Clover coverage and analyzes same-repository PRs
and pushes to the default branch. Configure the `SONAR_TOKEN` Actions secret and
`SONAR_ORGANIZATION` Actions variable (organization-level bindings may be reused).
Project mapping and source paths are in `sonar-project.properties`. Fork PRs run tests
without access to the scanner token. Coverage uses the existing PHPUnit scopes;
unmeasured application code is not excluded from SonarQube. Frontend tests and builds
run separately; JavaScript/TypeScript coverage is not collected by this PHP rollout.
Generated assets and third-party dependencies are not application analysis inputs.
