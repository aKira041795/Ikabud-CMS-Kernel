# Contributing to Ikabud Kernel

Thank you for your interest in contributing to Ikabud Kernel! This document provides guidelines and instructions for contributing to the project.

---

## Table of Contents

1. [Code of Conduct](#code-of-conduct)
2. [Getting Started](#getting-started)
3. [Development Setup](#development-setup)
4. [How to Contribute](#how-to-contribute)
5. [Coding Standards](#coding-standards)
6. [Testing Guidelines](#testing-guidelines)
7. [Documentation](#documentation)
8. [Pull Request Process](#pull-request-process)
9. [Community](#community)

---

## Code of Conduct

### Our Pledge

We are committed to providing a welcoming and inclusive environment for all contributors, regardless of experience level, gender, gender identity and expression, sexual orientation, disability, personal appearance, body size, race, ethnicity, age, religion, or nationality.

### Expected Behavior

- Be respectful and considerate
- Use welcoming and inclusive language
- Accept constructive criticism gracefully
- Focus on what is best for the community
- Show empathy towards other community members

### Unacceptable Behavior

- Harassment, trolling, or discriminatory comments
- Personal or political attacks
- Publishing others' private information
- Other conduct which could reasonably be considered inappropriate

---

## Getting Started

### Prerequisites

Before contributing, ensure you have:

- PHP 8.1 or higher
- Composer
- MySQL/MariaDB
- Git
- Basic understanding of:
  - PHP and OOP concepts
  - RESTful APIs
  - Database design
  - Linux/Unix systems

### Finding Issues to Work On

1. **Good First Issues**: Look for issues labeled `good first issue`
2. **Help Wanted**: Check issues labeled `help wanted`
3. **Bug Reports**: Browse open bug reports
4. **Feature Requests**: Review feature requests

---

## Development Setup

### 1. Fork and Clone

```bash
# Fork the repository on GitHub
# Then clone your fork
git clone https://github.com/yourusername/ikabud-kernel.git
cd ikabud-kernel

# Add upstream remote
git remote add upstream https://github.com/originalowner/ikabud-kernel.git
```

### 2. Install Dependencies

```bash
# Install PHP dependencies
composer install

# Install Node.js dependencies (for admin UI)
cd admin
npm install
cd ..
```

### 3. Configure Environment

```bash
# Copy environment file
cp .env.example .env.dev

# Edit configuration
nano .env.dev

# Set development mode
APP_ENV=development
APP_DEBUG=true
```

### 4. Set Up Database

```bash
# Create development database
mysql -u root -p
CREATE DATABASE ikabud_kernel_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;

# Import schema
mysql -u root -p ikabud_kernel_dev < database/schema.sql
```

### 5. Start Development Server

```bash
# PHP built-in server
php -S localhost:8000 -t public

# Or use the serve script
composer serve
```

### 6. Run Tests

```bash
# Run all tests
composer test

# Run specific test suite
./vendor/bin/phpunit tests/Unit

# Run with coverage
composer test-coverage
```

---

## How to Contribute

### Reporting Bugs

When reporting bugs, please include:

1. **Clear title** - Descriptive summary of the issue
2. **Description** - Detailed explanation of the problem
3. **Steps to reproduce** - Exact steps to reproduce the issue
4. **Expected behavior** - What should happen
5. **Actual behavior** - What actually happens
6. **Environment** - OS, PHP version, database version
7. **Logs** - Relevant error messages or logs
8. **Screenshots** - If applicable

**Bug Report Template:**

```markdown
## Bug Description
[Clear description of the bug]

## Steps to Reproduce
1. Step one
2. Step two
3. Step three

## Expected Behavior
[What should happen]

## Actual Behavior
[What actually happens]

## Environment
- OS: Ubuntu 22.04
- PHP: 8.2.0
- Database: MySQL 8.0.32
- Ikabud Kernel: 1.0.0

## Logs
```
[Paste relevant logs here]
```

## Screenshots
[If applicable]
```

### Suggesting Features

When suggesting features, please include:

1. **Use case** - Why is this feature needed?
2. **Description** - Detailed explanation of the feature
3. **Benefits** - How will this improve Ikabud Kernel?
4. **Implementation ideas** - Suggestions on how to implement
5. **Alternatives** - Other solutions you've considered

**Feature Request Template:**

```markdown
## Feature Description
[Clear description of the feature]

## Use Case
[Why is this needed?]

## Benefits
- Benefit 1
- Benefit 2

## Implementation Ideas
[How could this be implemented?]

## Alternatives Considered
[Other solutions you've thought about]
```

### Contributing Code

1. **Create a branch**
   ```bash
   git checkout -b feature/your-feature-name
   # OR
   git checkout -b fix/bug-description
   ```

2. **Make your changes**
   - Write clean, documented code
   - Follow coding standards
   - Add tests for new features
   - Update documentation

3. **Commit your changes**
   ```bash
   git add .
   git commit -m "feat: add amazing feature"
   ```

4. **Push to your fork**
   ```bash
   git push origin feature/your-feature-name
   ```

5. **Open a Pull Request**
   - Go to GitHub and create a PR
   - Fill out the PR template
   - Link related issues

---

## Coding Standards

### PHP Standards

We follow **PSR-12** coding standards with some additions:

#### File Structure

```php
<?php

declare(strict_types=1);

namespace IkabudKernel\Component;

use IkabudKernel\Core\Kernel;
use IkabudKernel\Core\ProcessManager;

/**
 * Class description
 * 
 * Detailed explanation of what this class does
 */
class ExampleClass
{
    private Kernel $kernel;
    
    public function __construct(Kernel $kernel)
    {
        $this->kernel = $kernel;
    }
    
    /**
     * Method description
     * 
     * @param string $param Parameter description
     * @return bool Return value description
     */
    public function exampleMethod(string $param): bool
    {
        // Implementation
        return true;
    }
}
```

#### Naming Conventions

- **Classes**: PascalCase (`ProcessManager`)
- **Methods**: camelCase (`createInstance`)
- **Variables**: camelCase (`$instanceId`)
- **Constants**: UPPER_SNAKE_CASE (`MAX_INSTANCES`)
- **Files**: PascalCase matching class name (`ProcessManager.php`)

#### Code Style

```php
// Good
if ($condition) {
    doSomething();
} else {
    doSomethingElse();
}

// Bad
if($condition){
    doSomething();
}
else{
    doSomethingElse();
}

// Good - Type hints and return types
public function processData(array $data): bool
{
    return true;
}

// Bad - No type hints
public function processData($data)
{
    return true;
}
```

#### Documentation

```php
/**
 * Create a new CMS instance
 * 
 * This method creates a new CMS instance with the specified configuration.
 * It validates the configuration, creates necessary directories, and
 * registers the instance in the database.
 * 
 * @param string $instanceId Unique identifier for the instance
 * @param array $config Instance configuration
 * @return array Instance information including PID and status
 * @throws InvalidArgumentException If configuration is invalid
 * @throws RuntimeException If instance creation fails
 */
public function createInstance(string $instanceId, array $config): array
{
    // Implementation
}
```

### JavaScript/TypeScript Standards

For the admin UI, we follow:

- **ESLint** configuration
- **Prettier** for formatting
- **TypeScript** strict mode

```typescript
// Good
interface InstanceConfig {
  instanceId: string;
  cmsType: 'wordpress' | 'joomla' | 'drupal';
  status: 'running' | 'stopped';
}

const createInstance = async (config: InstanceConfig): Promise<void> => {
  // Implementation
};

// Bad
const createInstance = async (config) => {
  // Implementation
};
```

---

## Testing Guidelines

### Writing Tests

```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use IkabudKernel\Core\ProcessManager;

class ProcessManagerTest extends TestCase
{
    private ProcessManager $processManager;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->processManager = new ProcessManager();
    }
    
    public function testCreateInstance(): void
    {
        $config = [
            'instance_id' => 'test-001',
            'cms_type' => 'wordpress',
        ];
        
        $result = $this->processManager->createInstance('test-001', $config);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('pid', $result);
        $this->assertArrayHasKey('status', $result);
    }
}
```

### Test Coverage

- **Minimum coverage**: 80%
- **Critical paths**: 100% coverage
- **New features**: Must include tests
- **Bug fixes**: Must include regression tests

### Running Tests

```bash
# All tests
composer test

# Specific test
./vendor/bin/phpunit tests/Unit/ProcessManagerTest.php

# With coverage
composer test-coverage

# Watch mode
./vendor/bin/phpunit --watch
```

---

## Documentation

### Code Documentation

- All public methods must have PHPDoc comments
- Complex logic should have inline comments
- Use clear, descriptive variable names

### User Documentation

When adding features, update:

- **README.md** - If it affects getting started
- **INSTALL.md** - If it affects installation
- **API.md** - If you add/modify API endpoints
- **CLI.md** - If you add/modify CLI commands
- **Relevant guides** - In the `docs/` directory

### Documentation Style

```markdown
# Feature Name

## Overview
Brief description of the feature

## Usage
How to use the feature

## Examples
```bash
# Example command
ikabud example-command
```

## Parameters
- `param1` - Description
- `param2` - Description

## Notes
Additional information
```

---

## Pull Request Process

### Before Submitting

- [ ] Code follows style guidelines
- [ ] All tests pass
- [ ] New tests added for new features
- [ ] Documentation updated
- [ ] Commit messages follow conventions
- [ ] No merge conflicts
- [ ] Branch is up to date with main

### PR Template

```markdown
## Description
[Clear description of changes]

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Related Issues
Fixes #123
Related to #456

## Testing
- [ ] Unit tests added/updated
- [ ] Integration tests added/updated
- [ ] Manual testing performed

## Checklist
- [ ] Code follows style guidelines
- [ ] Self-review completed
- [ ] Comments added for complex code
- [ ] Documentation updated
- [ ] No new warnings generated
- [ ] Tests pass locally
```

### Review Process

1. **Automated checks** - CI/CD runs tests
2. **Code review** - Maintainers review code
3. **Feedback** - Address review comments
4. **Approval** - At least one maintainer approval required
5. **Merge** - Maintainer merges the PR

### Commit Message Format

We use **Conventional Commits**:

```
<type>(<scope>): <subject>

<body>

<footer>
```

**Types:**
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style changes (formatting)
- `refactor`: Code refactoring
- `test`: Adding or updating tests
- `chore`: Maintenance tasks

**Examples:**

```
feat(kernel): add process isolation support

Implement process isolation using PHP-FPM pools to ensure
each CMS instance runs in its own isolated environment.

Closes #123
```

```
fix(api): resolve authentication token expiration issue

Fixed bug where JWT tokens were expiring prematurely due to
incorrect timezone handling.

Fixes #456
```

---

## Community

### Communication Channels

- **GitHub Issues**: Bug reports and feature requests
- **GitHub Discussions**: General questions and discussions
- **Discord**: Real-time chat with the community
- **Email**: dev@ikabud.com for private inquiries

### Getting Help

- Check existing documentation
- Search closed issues
- Ask in GitHub Discussions
- Join our Discord server

### Recognition

Contributors are recognized in:
- **CHANGELOG.md** - For significant contributions
- **README.md** - In the contributors section
- **GitHub Contributors** - Automatic recognition

---

## License

By contributing to Ikabud Kernel, you agree that your contributions will be licensed under the MIT License.

---

## Questions?

If you have questions about contributing, feel free to:
- Open a discussion on GitHub
- Join our Discord server
- Email us at dev@ikabud.com

---

**Thank you for contributing to Ikabud Kernel!** 🚀
