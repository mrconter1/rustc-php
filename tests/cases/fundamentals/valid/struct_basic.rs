// exit: 0
// stdout: 10, 20
struct Point {
    x: i32,
    y: i32,
}

fn main() {
    let p = Point { x: 10, y: 20 };
    println!("{}, {}", p.x, p.y);
}
