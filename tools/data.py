import numpy as np
from dataclasses import dataclass


@dataclass
class Vector:
    x1: np.float32
    y1: np.float32
    x2: np.float32
    y2: np.float32
