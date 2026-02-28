// exit: 0
// stdout: 30
struct Point {
    x: i32,
    y: i32,
}

fn main() {
    let p = Point { x: 10, y: 20 };
    let sum = p.x + p.y;
    println!("{}", sum);
}
