#!/usr/bin/env bash

set -euo pipefail

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-root}
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

download() {
    if command -v curl >/dev/null; then
        curl -s "$1" >"$2"
    else
        wget -nv -O "$2" "$1"
    fi
}

if [[ "$WP_VERSION" == "latest" ]]; then
    download http://api.wordpress.org/core/version-check/1.7/ "$TMPDIR/wp-latest.json"
    WP_VERSION=$(grep -o '"version":"[^"]*' "$TMPDIR/wp-latest.json" | sed 's/"version":"//' | head -1)
fi
WP_TESTS_TAG="tags/$WP_VERSION"

install_wp() {
    if [ -d "$WP_CORE_DIR" ]; then return; fi
    mkdir -p "$WP_CORE_DIR"
    download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "$TMPDIR/wordpress.tar.gz"
    tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
    download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

install_test_suite() {
    if [ ! -d "$WP_TESTS_DIR" ]; then
        mkdir -p "$WP_TESTS_DIR"
        svn export --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
        svn export --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
    fi

    if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
        download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
        local cfg="$WP_TESTS_DIR/wp-tests-config.php"
        sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$cfg"
        sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$cfg"
        sed -i "s/yourusernamehere/$DB_USER/" "$cfg"
        sed -i "s/yourpasswordhere/$DB_PASS/" "$cfg"
        sed -i "s|localhost|${DB_HOST}|" "$cfg"
    fi
}

install_wp
install_test_suite
echo "WP test suite installed: WP_TESTS_DIR=$WP_TESTS_DIR WP_CORE_DIR=$WP_CORE_DIR"
