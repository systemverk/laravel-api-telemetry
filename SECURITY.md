# Security Policy

## Supported Versions

Security fixes are provided for the latest minor release.

## Reporting a Vulnerability

Please do not open a public issue for security problems.

Report vulnerabilities privately through
[GitHub Security Advisories](https://github.com/systemverk/laravel-api-telemetry/security/advisories/new),
or by email to eirik386@gmail.com.

You can expect an initial response within seven days.

## Data Handling Notes

This package records request metadata. Reviewers should be aware that:

- IP addresses are stored only as a salted SHA-256 digest, or not at all when
  `api_telemetry.privacy.hash_ips` is disabled.
- Request and response bodies are never recorded.
- No headers are recorded except the configured correlation-id header.
- Request paths *are* stored verbatim. If your API places secrets in the URL
  path rather than in headers or the body, exclude those routes via
  `api_telemetry.except`.
