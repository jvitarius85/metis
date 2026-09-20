# Developer Guide

Use the documents below as the current developer baseline for Metis.

## Core Docs

- [Request Lifecycle](./request-lifecycle.md)
- [Directory Structure](./directory-structure.md)
- [Coding Standards](./coding-standards.md)
- [Extension Points](./extension-points.md)

## Additional References

- [Help System](./help-system.md)
- [API Endpoints](../api/endpoints.md)
- [Security Model](../security/security-model.md)
- [Module Documentation](../modules/README.md)

## Practical Summary

- Route web requests through the shared front controller and router.
- Register AJAX controllers explicitly and validate request payloads at the boundary.
- Reuse shared services before introducing new abstractions.
- Treat `system/src/Metis` and the split `metis-private` module tree as the authoritative runtime surfaces.
