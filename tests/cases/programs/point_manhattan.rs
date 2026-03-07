// stdout: 5
// stdout: 6
// stdout: 11
// exit: 11

struct Point {
    x: i32,
    y: i32,
}

fn abs_diff(a: i32, b: i32) -> i32 {
    if a >= b {
        a - b
    } else {
        b - a
    }
}

fn manhattan(p: Point, q: Point) -> i32 {
    abs_diff(p.x, q.x) + abs_diff(p.y, q.y)
}

fn main() {
    let p0 = Point { x: 0, y: 0 };
    let p1 = Point { x: 3, y: 2 };
    let p2 = Point { x: 6, y: 5 };
    println!("{}", manhattan(p0, p1));
    println!("{}", manhattan(p1, p2));
    println!("{}", manhattan(p0, p2));
    exit(manhattan(p0, p2));
}
