# Contributing to Inventoros

First off, thank you for considering contributing to Inventoros! It's people like you that make Inventoros such a great tool for the community.

## Code of Conduct

This project and everyone participating in it is governed by our [Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold this code. Please report unacceptable behavior to the project maintainers.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the existing issues to avoid duplicates. When you create a bug report, include as many details as possible:

- **Use a clear and descriptive title**
- **Describe the exact steps to reproduce the problem**
- **Provide specific examples to demonstrate the steps**
- **Describe the behavior you observed and what you expected**
- **Include screenshots if relevant**
- **Include your environment details** (OS, PHP version, Laravel version, etc.)

#### Bug Report Template

```markdown
**Description**
A clear and concise description of the bug.

**Steps to Reproduce**
1. Go to '...'
2. Click on '...'
3. Scroll down to '...'
4. See error

**Expected Behavior**
What you expected to happen.

**Actual Behavior**
What actually happened.

**Environment**
- OS: [e.g., macOS 13.0]
- PHP Version: [e.g., 8.2.10]
- Laravel Version: [e.g., 12.0]
- Database: [e.g., MySQL 8.0.33]

**Additional Context**
Any other information about the problem.
```

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, include:

- **Use a clear and descriptive title**
- **Provide a detailed description of the suggested enhancement**
- **Explain why this enhancement would be useful**
- **List any similar features in other systems** (if applicable)

### Pull Requests

We actively welcome your pull requests! Here's the process:

1. **Fork the repository** and create your branch from `main`
2. **Make your changes** following our coding standards
3. **Add tests** if you've added functionality
4. **Update documentation** if you've changed APIs or added features
5. **Ensure the test suite passes** (`composer test`)
6. **Run code formatting** (`./vendor/bin/pint`)
7. **Write a clear commit message**
8. **Submit your pull request**

## Development Setup

### Prerequisites

- PHP 8.2 or higher
- Composer
- Node.js 18+ and npm
- MySQL 8.0+ or PostgreSQL 13+
- Git

### Getting Started

```bash
# Fork and clone the repository
git clone https://github.com/inventoros/inventoros.git
cd inventoros

# Install dependencies
composer install
npm install

# Set up environment
cp .env.example .env
php artisan key:generate

# Configure your database in .env, then migrate
php artisan migrate

# Run the development server
composer dev
```

### Running Tests

```bash
# Run all tests
composer test

# Run specific test types
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit

# Run with coverage (requires xdebug)
php artisan test --coverage
```

### Code Formatting

We use Laravel Pint for code formatting:

```bash
# Format all code
./vendor/bin/pint

# Check formatting without changes
./vendor/bin/pint --test
```

## Coding Standards

### PHP

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards
- Use type hints for parameters and return types
- Write descriptive variable and function names
- Add PHPDoc blocks for classes and complex methods
- Keep methods focused and single-purpose

#### Example

```php
<?php

namespace App\Services;

use App\Models\Product;
use App\Exceptions\InsufficientStockException;

class InventoryService
{
    /**
     * Reduce stock quantity for a product.
     *
     * @throws InsufficientStockException
     */
    public function reduceStock(Product $product, int $quantity): void
    {
        if ($product->stock < $quantity) {
            throw new InsufficientStockException(
                "Insufficient stock for product: {$product->name}"
            );
        }

        $product->decrement('stock', $quantity);
    }
}
```

### JavaScript/Vue

- Use ES6+ syntax
- Follow Vue 3 Composition API patterns
- Use TypeScript for type safety (when applicable)
- Keep components small and focused
- Use meaningful component and variable names

#### Example

```vue
<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  product: {
    type: Object,
    required: true
  }
})

const quantity = ref(0)

const isInStock = computed(() => {
  return props.product.stock > 0
})

const handleAddToCart = () => {
  // Implementation
}
</script>

<template>
  <div class="product-card">
    <h3>{{ product.name }}</h3>
    <p v-if="isInStock">In Stock: {{ product.stock }}</p>
    <p v-else class="text-red-500">Out of Stock</p>
  </div>
</template>
```

### Database

- Use migrations for all schema changes
- Write descriptive migration names: `create_products_table`, `add_sku_to_products_table`
- Add foreign key constraints where appropriate
- Index columns used in WHERE clauses or joins
- Use factories for test data

#### Example Migration

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('stock')->default(0);
            $table->decimal('price', 10, 2);
            $table->foreignId('category_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('sku');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
```

## Git Commit Messages

- Use the present tense ("Add feature" not "Added feature")
- Use the imperative mood ("Move cursor to..." not "Moves cursor to...")
- Limit the first line to 72 characters or less
- Reference issues and pull requests liberally after the first line

### Commit Message Format

```
feat: add barcode scanning support

- Implement barcode scanner integration
- Add barcode field to products table
- Create barcode validation service

Closes #123
```

### Commit Types

- `feat:` New feature
- `fix:` Bug fix
- `docs:` Documentation only changes
- `style:` Code style changes (formatting, semicolons, etc.)
- `refactor:` Code refactoring
- `perf:` Performance improvements
- `test:` Adding or updating tests
- `chore:` Maintenance tasks, dependency updates

## Testing Guidelines

### Writing Tests

- Write tests for all new features
- Maintain or improve code coverage
- Use descriptive test method names
- Follow the Arrange-Act-Assert pattern
- Mock external dependencies

#### Example Feature Test

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_product(): void
    {
        // Arrange
        $user = User::factory()->create();
        $productData = [
            'sku' => 'TEST-001',
            'name' => 'Test Product',
            'price' => 99.99,
            'stock' => 10,
        ];

        // Act
        $response = $this->actingAs($user)
            ->post('/api/products', $productData);

        // Assert
        $response->assertStatus(201);
        $this->assertDatabaseHas('products', [
            'sku' => 'TEST-001',
            'name' => 'Test Product',
        ]);
    }
}
```

## Documentation

- Update README.md if you change functionality
- Document all public APIs and methods
- Add inline comments for complex logic
- Update CHANGELOG.md following Keep a Changelog format
- Create or update relevant docs in the `/docs` directory

## Plugin Development

If you're developing a plugin for Inventoros:

1. Follow the plugin API conventions (documentation coming soon)
2. Include a `plugin.json` manifest file
3. Provide clear installation instructions
4. Write tests for your plugin
5. Document configuration options

## Questions?

Don't hesitate to ask questions! You can:

- Open an issue with the "question" label
- Start a discussion in GitHub Discussions
- Reach out to the maintainers

## Recognition

Contributors will be recognized in:

- The project README
- Release notes for significant contributions
- Our community acknowledgments

Thank you for contributing to Inventoros!
