---
title: Fix webpack plugin config order
date: 2024-12-11
area: storefront
tags: [changelog]
---

## Problem
Due to the current order in the webpack `pluginConfigs` method, configurations from parent themes are skipped. This can break child themes if they rely on a parent theme's webpack configuration.

## Solution
Simply change the order of the `if` statements in the `pluginConfigs` method so that a webpack configuration is considered first.