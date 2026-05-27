---
layout: default
title: API Reference
nav_order: 65
description: Full OpenAPI 3.1 reference for the Simple WP Helpdesk REST API (swh/v1 namespace).
---

# API Reference

Machine-readable OpenAPI 3.1 contract for the `swh/v1` REST namespace.

- **Spec source (YAML):** [`openapi.yaml`](openapi.yaml)
- **Spec artifact (JSON):** [`openapi.json`](openapi.json) — what Postman / Insomnia import
- **JSON endpoint on a running site:** `GET /wp-json/swh/v1/openapi.json`

If you operate a Simple WP Helpdesk site, the same viewer is also available under **Tools → Helpdesk API Reference** in the WordPress admin.

<div id="redoc-container"></div>
<script src="{{ '/assets/redoc.standalone.js' | relative_url }}"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof Redoc === 'undefined') { return; }
    Redoc.init(
      "{{ '/openapi.yaml' | relative_url }}",
      { scrollYOffset: 56, hideDownloadButton: false },
      document.getElementById('redoc-container')
    );
  });
</script>
