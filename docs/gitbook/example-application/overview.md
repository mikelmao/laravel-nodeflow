# Flood-alert example application

> **Experimental:** Nodeflow is pre-release software. Treat this walkthrough as a starting point, review the package's current behavior, and test the application-owned side effects before using it for real alerts.

This illustrative walkthrough builds a tenant-aware flood-alert journey. `Organization` is the tenant, `User` is the subject, and the application owns both message records and message delivery. Nodeflow owns the graph, durable waits, version pinning, and run routing.

This documentation provides code examples only; no downloadable or hosted demo application is provided.

The journey is deliberately small:

```mermaid
flowchart LR
    alert["Flood alert"] --> sendAlert["Send alert\napp.send_message"]
    sendAlert --> waitOffer["Wait 5 minutes\ncore.wait"]
    waitOffer --> sendOffer["Send offer\napp.send_message"]
    sendOffer --> waitResponse["Wait 1 day\ncore.wait"]
    waitResponse --> clicked{"clicked_offer?\ncore.condition"}
    clicked -->|yes| done["Exit\ncore.exit"]
    clicked -->|no| followUp["Send follow-up\napp.send_message"]
    followUp --> done
```

In words: **Flood alert → send alert → wait → send offer → wait → clicked?** A `yes` exits; a `no` sends a follow-up and exits. The graph uses trigger type `app.flood_alert`, node type `app.send_message`, and the `clicked_offer` subject attribute throughout.

## What this teaches

You will define the host models and resolvers, turn `FloodAlertDispatched` into tenant-specific audiences, make a safe message node, publish a graph, and test its important boundaries. The example assumes that every `User` belongs to exactly one `Organization`, that an alert already identifies affected users by organization, and that an application event is dispatched only after the alert has been recorded.

Messaging is intentionally host-owned. `DemoMessage` is an application record used here to stand in for a real delivery service; the node's idempotency key and test-mode branch are the same boundaries a notification, email, SMS, or API integration needs. Nodeflow does not create `Organization`, `User`, `FloodAlert`, or `DemoMessage` tables, and it does not decide who may send messages.

An alert can include users from more than one organization. The trigger returns one audience per organization, and Nodeflow starts one run for each matching active flow in each tenant. It is therefore incorrect to assume one dispatched event always produces one run.

## Page map

- [Application setup](application-setup.md) establishes host data, tenancy, registration, authorization, and routes.
- [Flood-alert workflow](flood-alert-workflow.md) implements the node, trigger, graph, publishing, dispatch, and conversion cancellation.
- [Testing the workflow](testing-the-workflow.md) supplies focused Pest tests for the application boundary and package behavior.

## Next step

Set up the host-owned data and contracts in [Application setup](application-setup.md).
