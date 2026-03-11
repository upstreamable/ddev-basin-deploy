[![add-on registry](https://img.shields.io/badge/DDEV-Add--on_Registry-blue)](https://addons.ddev.com)
[![tests](https://github.com/upstreamable/ddev-basin-deploy/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/upstreamable/ddev-basin-deploy/actions/workflows/tests.yml?query=branch%3Amain)
[![last commit](https://img.shields.io/github/last-commit/upstreamable/ddev-basin-deploy)](https://github.com/upstreamable/ddev-basin-deploy/commits)
[![release](https://img.shields.io/github/v/release/upstreamable/ddev-basin-deploy)](https://github.com/upstreamable/ddev-basin-deploy/releases/latest)

# DDEV Basin Deploy

## Overview

This add-on integrates Basin Deploy into your [DDEV](https://ddev.com/) project.

## Installation

```bash
ddev add-on get upstreamable/ddev-basin-deploy
ddev restart
```

After installation, make sure to commit the `.ddev` directory to version control.

## Usage

| Command | Description |
| ------- | ----------- |
| `ddev describe` | View service status and used ports for Basin Deploy |
| `ddev logs -s basin-deploy` | Check Basin Deploy logs |

## Advanced Customization

To change the Docker image:

```bash
ddev dotenv set .ddev/.env.basin-deploy --basin-deploy-docker-image="ddev/ddev-utilities:latest"
ddev add-on get upstreamable/ddev-basin-deploy
ddev restart
```

Make sure to commit the `.ddev/.env.basin-deploy` file to version control.

All customization options (use with caution):

| Variable | Flag | Default |
| -------- | ---- | ------- |
| `BASIN_DEPLOY_DOCKER_IMAGE` | `--basin-deploy-docker-image` | `ddev/ddev-utilities:latest` |

## Credits

**Contributed and maintained by [@upstreamable](https://github.com/upstreamable)**
