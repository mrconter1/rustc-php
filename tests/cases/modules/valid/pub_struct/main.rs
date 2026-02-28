// exit: 30

mod geometry;

use crate::geometry::Point;

fn main() {
    let p = Point { x: 10, y: 20 };
    exit(p.x + p.y);
}
