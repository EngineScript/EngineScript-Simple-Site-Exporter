---
name: "WordPress Version Compatibility Test Failure"
about: "Automated issue created when WordPress version compatibility tests fail"
title: "WordPress Compatibility Failure - WP {{ env.WP_VERSION }} / PHP {{ env.PHP_VERSION }} / {{ env.DEPENDENCY_VERSIONS }} / {{ env.FAILURE_STAGE }}"
labels: ["bug", "compatibility", "wordpress-version"]
assignees: []
---

## WordPress Version Compatibility Test Failed

The compatibility job failed. An installation or bootstrap failure is not proof of plugin incompatibility.

**Failure stage:** `{{ env.FAILURE_STAGE }}`

**Failure Details:**

- **PHP Version:** {{ env.PHP_VERSION }}
- **WordPress Version:** {{ env.WP_VERSION }}
- **Dependency Versions:** {{ env.DEPENDENCY_VERSIONS }}
- **Workflow Run:** [View Details]({{ env.WORKFLOW_URL }})
- **Run ID:** {{ env.RUN_ID }}

**What happened:**
Inspect the failed stage and its logs for WordPress {{ env.WP_VERSION }}, PHP {{ env.PHP_VERSION }}, and {{ env.DEPENDENCY_VERSIONS }} dependencies. Diagnose infrastructure separately from failed test assertions.

**What needs to be done:**

1. Review the test output in the failed workflow run
2. Identify compatibility issues with WordPress {{ env.WP_VERSION }}
3. Fix any deprecated function calls or API usage
4. Ensure plugin works correctly with this WordPress version
5. Update plugin compatibility metadata if needed
6. Re-run this GitHub matrix cell to validate the fix

**Potential Issues:**

- Deprecated WordPress functions
- Changed WordPress APIs
- PHP version incompatibilities with this WordPress version
- Plugin initialization problems

**Resources:**

- [WordPress Backward Compatibility](https://developer.wordpress.org/plugins/plugin-basics/determining-plugin-and-content-directories/)
- [WordPress Deprecated Functions](https://developer.wordpress.org/reference/functions/)

This issue was automatically created by the CI/CD pipeline.
