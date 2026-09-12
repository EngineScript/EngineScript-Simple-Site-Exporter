---
name: "Psalm Static Analysis Failure"
about: "Automated issue created when Psalm static analysis fails"
title: "Psalm Static Analysis Failed"
labels: ["bug", "psalm", "static-analysis"]
assignees: []
---

## Psalm Static Analysis Failed

The Psalm job failed. Inspect the failure stage before attributing the failure to static analysis.

**Failure stage:** `{{ env.FAILURE_STAGE }}`

**Failure Details:**

- **PHP Version:** {{ env.PHP_VERSION }}
- **Workflow Run:** [View Details]({{ env.WORKFLOW_URL }})
- **Run ID:** {{ env.RUN_ID }}

**What happened:**
The stage above identifies where execution failed. If analysis ran, inspect the Psalm output; otherwise fix the prerequisite.

**What needs to be done:**

1. Review the Psalm output in the failed workflow run
2. Address static analysis issues such as:
   - Type errors
   - Undefined variables or methods
   - Incorrect return types
   - Unused code
   - Potential null pointer issues
3. Re-run the GitHub workflow to validate the fix

**Resources:**

- [Psalm Documentation](https://psalm.dev/)
- [Psalm Error Levels](https://psalm.dev/docs/running_psalm/error_levels/)

This issue was automatically created by the CI/CD pipeline.
