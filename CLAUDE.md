# Claude Code Instructions

## Git Commit Guidelines

**IMPORTANT:** Never include "Co-Authored-By: Claude" in commit messages. All commits should be attributed to the human developer only.

## Worktree Directory

Use `.worktrees/` for all git worktree operations (already configured in .gitignore).

## Project Standards

### Code Style
- Follow existing Laravel and Vue.js conventions
- Use Tailwind CSS for styling
- Follow PSR-12 for PHP code
- Use Vue 3 Composition API with `<script setup>`

### Testing
- Run `composer test` for PHP tests
- Backend tests use PHPUnit
- Frontend tests use Playwright (E2E)

### Database
- Always create migrations for schema changes
- Use factories and seeders for test data
- Follow multi-tenant architecture (organization_id scoping)

### Permissions
- All features should respect role-based permissions
- Use permission checks in controllers and views
- Common permissions: `products.view`, `orders.manage`, `users.manage`, etc.

### API
- RESTful API under `/api/v1/`
- Use Sanctum for authentication
- Return JSON resources for responses
- All API routes are organization-scoped

## Multi-Tenant Architecture

This application uses organization-based multi-tenancy:
- Every model should have `organization_id`
- Always scope queries by organization
- Users belong to one organization
- Data is completely isolated between organizations

## Email Configuration

Email settings are stored per-organization in the `settings` table (to be implemented).
