[![add-on registry](https://img.shields.io/badge/DDEV-Add--on_Registry-blue)](https://addons.ddev.com)
[![tests](https://github.com/upstreamable/ddev-basin-deploy/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/upstreamable/ddev-basin-deploy/actions/workflows/tests.yml?query=branch%3Amain)
[![last commit](https://img.shields.io/github/last-commit/upstreamable/ddev-basin-deploy)](https://github.com/upstreamable/ddev-basin-deploy/commits)
[![release](https://img.shields.io/github/v/release/upstreamable/ddev-basin-deploy)](https://github.com/upstreamable/ddev-basin-deploy/releases/latest)

# DDEV Basin Deploy

## Overview

This add-on integrates Basin Deploy with ansistrano into your [DDEV](https://ddev.com/) project.

## Installation

```bash
ddev add-on get  https://github.com/upstreamable/ddev-basin-deploy/tarball/main
ddev restart
```

## Usage

Add to `.ddev/.env.web` the following variables
```
ANSIBLE_REMOTE_USER=ubuntu
ANSIBLE_REMOTE_HOST=1.1.1.1
```
Replace by the values you would use in a ssh connection such as `ubuntu@1.1.1.1`.

## Advanced Customization

## Credits

**Contributed and maintained by [@upstreamable](https://github.com/upstreamable)**
