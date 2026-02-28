// error: does not implement required method

struct Point {
    x: i32,
    y: i32,
}

trait Greet {
    fn greet(&self) -> i32;
    fn hello(&self) -> i32;
}

impl Greet for Point {
    fn greet(&self) -> i32 {
        self.x + self.y
    }
}

fn main() {
    let p = Point { x: 10, y: 20 };
    p.greet();
}
