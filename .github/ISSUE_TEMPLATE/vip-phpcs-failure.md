---
title: WordPress VIP Coding Standards Failure - PHP {{ env.PHP_VERSION }}
labels: ['vip-standards', 'coding-standards', 'needs-review', 'php-{{ env.PHP_VERSION }}']
assignees: []
---

## WordPress VIP Coding Standards Failure

The VIP coding-standards job failed. Inspect the failure stage and original check output.

**Failure stage:** `{{ env.FAILURE_STAGE }}`

**PHP Version:** {{ env.PHP_VERSION }}
**Run ID:** {{ env.RUN_ID }}
**Workflow:** [View Failed Run]({{ env.WORKFLOW_URL }})

### Issue Description

If the VIP scan ran, its output concerns enterprise-level WordPress VIP requirements. Otherwise, resolve the failed prerequisite first.

### VIP Standards Focus Areas

The WordPress VIP Go coding standards check for:

🏢 **Enterprise Platform Requirements:**

- File system operation restrictions (VIP platform limitations)
- Performance and caching best practices for high-traffic sites
- Security vulnerabilities specific to enterprise WordPress environments
- User experience guidelines for enterprise-level WordPress

🚀 **Performance & Caching:**

- Uncached function usage patterns
- Database query optimization
- Remote data fetching best practices
- Resource-heavy operation detection

🔒 **VIP-Specific Security:**

- File operation security in restricted environments
- Admin bar removal restrictions for VIP support users
- Cookie and caching constraint validations
- Restricted function usage for platform stability

### Important Notes

⚠️ **VIP Standards Context:**

- Many VIP standards are specific to the WordPress VIP hosting platform
- Not all VIP recommendations may apply to standard WordPress installations
- Some restrictions are platform-specific (e.g., file system limitations)
- This scan helps ensure compatibility with enterprise WordPress environments

### Next Steps

1. **Review the workflow logs** to identify specific VIP standard violations
2. **Evaluate applicability** - determine which issues apply to your hosting environment
3. **Prioritize fixes** based on your deployment target:
   - **High Priority:** Security and performance issues
   - **Medium Priority:** General code quality improvements
   - **Low Priority:** VIP platform-specific restrictions (if not targeting VIP)
4. **Update code** to address applicable VIP standard violations
5. **Re-run the workflow** to verify fixes

### Resources

- [WordPress VIP Code Quality Standards](https://docs.wpvip.com/technical-references/code-quality-and-best-practices/)
- [VIP Coding Standards GitHub](https://github.com/Automattic/VIP-Coding-Standards)
- [WordPress VIP Platform Documentation](https://docs.wpvip.com/)
- [VIP Go File System Documentation](https://docs.wpvip.com/technical-references/vip-go-files-system/)

### Workflow Information

**Failed Workflow Run:** [View Details]({{ env.WORKFLOW_URL }})
**PHP Version Tested:** {{ env.PHP_VERSION }}
**Standards Used:** WordPress-VIP-Go ruleset

This issue was automatically created when the WordPress VIP coding standards check failed. Please review the specific violations in the workflow logs and address them according to your project's deployment requirements.
