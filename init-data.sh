#!/bin/bash
# Script to initialize data directory
# This will be run automatically on Railway deployment

mkdir -p data
chmod 755 data
touch data/.gitkeep

# Create empty JSON files if they don't exist
[ ! -f data/matches.json ] && echo '[]' > data/matches.json
[ ! -f data/matches_data.json ] && echo '{}' > data/matches_data.json
[ ! -f data/teams.json ] && echo '{}' > data/teams.json

chmod 644 data/*.json

echo "Data directory initialized"
