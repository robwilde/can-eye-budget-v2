RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --only-upgrade -o Dpkg::Options::=--force-confold $(dpkg -l "php${DDEV_PHP_VERSION}*" | awk '/^ii/ {print $2}') \
    && "php${DDEV_PHP_VERSION}" -r 'if (version_compare(PHP_VERSION, "8.4.27", "<")) { fwrite(STDERR, "PHP " . PHP_VERSION . " is below 8.4.27\n"); exit(1); }' \
    && npx playwright install-deps chromium \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
