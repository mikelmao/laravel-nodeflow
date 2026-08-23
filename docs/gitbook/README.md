# Laravel Nodeflow

> **Experimental:** Nodeflow is pre-release software. Review its current [project status](experimental/project-status.md) and test it carefully before relying on it for production automations.

This documentation helps Laravel teams give their users a safe, visual way to assemble and run long-lived automations.

## Why Nodeflow exists

Many useful automations change more often than an application should be deployed. A product team may want to send a flood alert, wait for an update, branch by a user's response, and stop when the alert is resolved. Requiring a developer and a deployment for every journey change makes that work slow and opaque.

Nodeflow lets application users assemble approved building blocks into those journeys. Your application still owns the domain rules and side effects; Nodeflow owns the workflow mechanics.

## What it provides

Nodeflow is a visual workflow builder and durable execution engine for Laravel applications whose users author long-running workflows. It provides durable waits and resumption, cancellation of subjects, cohort execution, immutable published versions, custom nodes and triggers, editor integration, and run inspection.

It also stores the graph that a run started with, so editing a flow later does not rewrite the behavior of a run already in progress.

## What your application provides

Your application supplies the things Nodeflow cannot know: tenants, subjects, authorization rules, business actions, and the events that matter in your domain. You implement nodes such as sending a message, resolvers that find your users, and gates that decide who may edit or start flows.

This boundary keeps customer-authored journeys constrained to capabilities you chose and implemented.

## A small example

An operations manager creates a flood-alert journey. When an alert is raised, the journey sends a message to affected residents, waits for the next update, and stops sending to residents who have already confirmed they are safe. The manager can adjust the published journey for future alerts while an earlier alert continues from its original version.

## Start here

1. [Introduction](getting-started/introduction.md) explains when Nodeflow is a good fit.
2. [Installation](getting-started/installation.md) installs the package and checks host wiring.
3. [Quick start](getting-started/quick-start.md) creates a minimal organization-and-user integration.
4. [Core concepts](getting-started/core-concepts.md) explains flows, versions, runs, and subjects.
5. [Experimental status](experimental/project-status.md) lists the package's current maturity and limitations.
