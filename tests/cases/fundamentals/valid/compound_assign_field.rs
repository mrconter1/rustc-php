// exit: 0
// stdout: 15
struct Point {
    x: i32,
    y: i32,
}

fn main() {
    let mut p = Point { x: 10, y: 20 };
    p.x += 5;
    println!("{}", p.x);
}
