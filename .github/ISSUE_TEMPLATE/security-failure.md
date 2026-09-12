---
name: "Security Workflow Failure"
about: "Automated issue for security workflow failures requiring triage"
title: "Security Workflow Failure"
labels: ["security", "needs-review"]
assignees: []
---

## Security Workflow Failure

The security job failed. This alone does not establish a vulnerability or its severity.

**Failure stage:** `{{ env.FAILURE_STAGE }}`

**Failure Details:**

- **PHP Version:** {{ env.PHP_VERSION }}
- **Workflow Run:** [View Details]({{ env.WORKFLOW_URL }})
- **Run ID:** {{ env.RUN_ID }}

**What happened:**
Inspect whether dependency setup, the advisory checker, or the source-pattern scan failed. Only the actual checker output can establish a vulnerability.

**What needs to be done:**

1. Review the security check output in the failed workflow run
2. Identify which dependencies have vulnerabilities
3. Update vulnerable dependencies to secure versions
4. If updates are not available, consider:
   - Finding alternative packages
   - Applying patches if available
   - Implementing workarounds
5. Test the application after updates

**Priority:** Triage the original failure first; assign vulnerability severity only when supported by evidence.

**Resources:**

- [Symfony Security Checker](https://github.com/FriendsOfPHP/security-advisories)
- [WordPress Security Best Practices](https://developer.wordpress.org/plugins/security/)

This issue was automatically created by the CI/CD pipeline.
