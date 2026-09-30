#!/bin/bash
# install.sh — Deploy bookstack to k3s
set -euo pipefail
# bookstack k3s deployment is managed via 08-infra-k3s
kubectl get deployment bookstack >/dev/null 2>&1 && {
  echo "bookstack already deployed"
  kubectl rollout status deployment/bookstack --timeout=120s
} || {
  echo "ERROR: bookstack k3s manifests not found. Run 08-infra-k3s/install.sh first."
  exit 1
}
