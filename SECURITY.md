# Security policy

## Reporting a vulnerability

Please **do not** report security problems in public issues, discussions or pull requests.

Report them privately through GitHub: open the [Security tab](https://github.com/suporterfid/statusconnect-ce/security) of this repository and choose **Report a vulnerability** ([private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability)). Only the maintainers see the report.

Include what you can of:

- the affected version (release tag) and configuration;
- the steps to reproduce, or a proof of concept;
- the impact you expect (for example data of another organization exposed, SSRF to an internal address, authentication bypass).

We aim to acknowledge a report within 5 business days and to agree on a disclosure date with you. Fixes ship in a new release with a GitHub security advisory that credits you, unless you prefer otherwise.

## Supported versions

Security fixes are made on the latest release. Please upgrade to it before reporting, and check whether the problem still happens there.

## Scope

In scope: the code in this repository, including the SSRF protections of monitor checks, tenant isolation, authentication, API keys, public status pages and the remote probe in `probe/`.

Out of scope: the configuration of your own server and hosting account, denial of service by volume, and findings that need a compromised administrator account.
