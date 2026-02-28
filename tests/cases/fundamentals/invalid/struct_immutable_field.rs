// error: Cannot assign to field of immutable variable
struct Point {
    x: i32,
    y: i32,
}

fn main() {
    let p = Point { x: 10, y: 20 };
    p.x = 99;
}
