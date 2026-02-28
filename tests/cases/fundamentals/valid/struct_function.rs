// exit: 0
// stdout: 30
struct Point {
    x: i32,
    y: i32,
}

fn add(a: i32, b: i32) -> i32 {
    a + b
}

fn main() {
    let p = Point { x: 10, y: 20 };
    let sum = add(p.x, p.y);
    println!("{}", sum);
}
