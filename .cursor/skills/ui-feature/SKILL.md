---
name: ui-feature
description: Implements UI features with state management, error handling, loading states, and consistent components. Use when building or modifying frontend UI, forms, pages, or when the user asks for UI implementation.
---

# UI Feature Skill

## Workflow

For any UI feature request:

1. **Propose a short plan** (components, state, data flow).
2. **Implement UI** using existing design system or component library.
3. **Add state** (local, context, or store as appropriate).
4. **Handle errors and loading** explicitly.
5. **Keep components consistent** with project patterns.

## State

- Choose appropriate state location (component, context, store).
- Avoid unnecessary global state; prefer local state when sufficient.
- Keep state shape minimal and predictable.

## Error Handling

- Surface errors to the user (toast, inline message, error boundary).
- Provide retry or recovery where applicable.
- Never leave users with a blank or broken screen on error.

## Loading States

- Show loading indicator during async operations (skeleton, spinner, disabled).
- Avoid layout shift; reserve space when possible.
- Disable actions during submit/loading to prevent double-submit.

## Consistent Components

- Reuse existing buttons, inputs, cards, modals from the project.
- Follow project styling (Tailwind, CSS modules, design tokens).
- Match spacing, typography, and interaction patterns.

## Checklist

- [ ] UI implemented with existing components
- [ ] State managed appropriately
- [ ] Error handling visible to user
- [ ] Loading states for async actions
- [ ] Consistent with project patterns
