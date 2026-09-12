---
name: "PHPStan Analysis Failure"
about: "Automated issue created when PHPStan static analysis fails"
title: "PHPStan for WordPress Analysis Failed - PHP {{ env.PHP_VERSION }}"
labels: ["bug", "phpstan", "static-analysis", "wordpress"]
assignees: []
---

## PHPStan for WordPress Static Analysis Failure

The PHPStan job failed. Inspect the failure stage before attributing the failure to static analysis.

**Failure stage:** `{{ env.FAILURE_STAGE }}`

**PHP Version:** {{ env.PHP_VERSION }}
**Workflow Run:** [{{ env.RUN_ID }}]({{ env.WORKFLOW_URL }})
**Date:** {{ date | date('YYYY-MM-DD') }}

### Description

The job may have stopped before PHPStan ran; use the failure stage to identify the original error.

### What happened?

If PHPStan ran and reported findings, they could indicate:

- Type safety issues specific to WordPress APIs
- Potential bugs or inconsistencies in WordPress plugin code
- WordPress coding standard violations
- Incorrect usage of WordPress functions or hooks

### Next Steps

1. Review the workflow logs at the link above
2. Check the specific PHPStan error messages
3. Fix any identified code issues
4. Ensure WordPress-specific type annotations are correct
5. Re-run the workflow to verify fixes

### Additional Information

- This check uses WordPress-specific PHPStan rules
- The analysis helps catch WordPress-related coding issues early
- Consider updating code to follow WordPress best practices

---
*This issue was automatically created by the PHPStan for WordPress workflow failure.*
