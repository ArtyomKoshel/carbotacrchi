"""Filter rule engine — decides if a lot should be skipped / flagged / marked inactive.

Usage:
    from filters import FilterEngine, load_rules

    engine = FilterEngine(load_rules())
    result = engine.evaluate(lot)
    if result.action == "skip":
        continue  # do not upsert
    if result.action == "mark_inactive":
        lot_patch_inactive(lot)
"""

from .rules import Rule, RuleSet, FilterResult, ACTION_ALLOW, ACTION_SKIP, ACTION_FLAG, ACTION_MARK_INACTIVE
from .engine import FilterEngine
from .config import load_rules

__all__ = [
    "Rule",
    "RuleSet",
    "FilterResult",
    "FilterEngine",
    "load_rules",
    "ACTION_ALLOW",
    "ACTION_SKIP",
    "ACTION_FLAG",
    "ACTION_MARK_INACTIVE",
]
