import os
import sys

PROJECT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, PROJECT_DIR)

# Для классического Timeweb hosting:
# замените путь ниже на site-specific virtualenv при необходимости.
# sys.path.insert(0, "/home/u/USER/venv/lib/python3.10/site-packages")

from backend.app import app as application
