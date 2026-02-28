// exit: 30

struct Point {
    x: i32,
    y: i32,
}

trait Greet {
    fn greet(&self) -> i32;
}

impl Greet for Point {
    fn greet(&self) -> i32 {
        self.x + self.y
    }
}

fn call_greet<T: Greet>(val: &T) -> i32 {
    val.greet()
}

fn main() {
    let p = Point { x: 10, y: 20 };
    exit(call_greet(&p));
}
