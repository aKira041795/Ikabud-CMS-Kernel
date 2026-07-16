# Contract and competitive-governance runner only.
# Browser E2E requires the documented Node/Playwright application environment.
FROM php:8.3-cli-alpine

LABEL org.ikabud.ark-workbench.scope="contract-and-benchmark-only"

RUN docker-php-ext-install pdo pdo_mysql

ENV ARK_WORKBENCH_RUNNER_SCOPE="contract-and-benchmark-only"

WORKDIR /workspace
COPY . .
ENTRYPOINT ["php", "scripts/workbench-ci.php"]
