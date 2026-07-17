---
name: architect
description: Create a bounded architectural implementation brief
argument-hint: Describe the feature, defect, or change
---

Act as the software architect.

Task supplied by the user:

${input:task:Describe the task}

Read AGENTS.md and inspect only the repository areas necessary to understand
this task.

Do not edit production code.
Do not run the full test suite.
Do not perform unrelated repository exploration.

Create or replace:

.ai/current-task.md

Use this structure:

# Current Task

## Objective

## Existing behavior

## Architectural constraints

## Files likely affected

## Implementation steps

## Acceptance criteria

## Required tests

## Risks

## Forbidden changes

Keep the plan direct and implementation-ready.

After writing the task file, report only:

- task file created;
- key architectural decision;
- paths DeepSeek should inspect.
