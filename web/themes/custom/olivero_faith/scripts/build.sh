#!/usr/bin/env bash
#
# Generate and copy Olivero css and fonts to our theme for override.

_my_sub_theme=${1:-"olivero_faith"}

if [[ ! -d web/core/themes/olivero ]];
then
  echo -e "[ERROR] Can not find Olivero theme, are you at the root of Drupal?"
  exit 1
fi

if [[ ! -d web/core/node_modules ]];
then
  yarn --cwd web/core install
fi

cp -r web/core/themes/olivero/css/ web/core/themes/olivero/css.orig/
cp -f web/themes/custom/$_my_sub_theme/css/variables.pcss.css web/core/themes/olivero/css/base/variables.pcss.css

# Build the theme
yarn --cwd web/core build:css

rm -rf web/themes/custom/$_my_sub_theme/css/base/*.css web/themes/custom/$_my_sub_theme/css/components web/themes/custom/$_my_sub_theme/css/layout
cp -r web/core/themes/olivero/css web/themes/custom/$_my_sub_theme/
cp -r web/core/themes/olivero/fonts web/themes/custom/$_my_sub_theme/fonts
rm -f web/themes/custom/$_my_sub_theme/css/**/*.pcss.css

# Set back Olivero variable file.
rm -rf web/core/themes/olivero/css/
mv web/core/themes/olivero/css.orig/ web/core/themes/olivero/css/
