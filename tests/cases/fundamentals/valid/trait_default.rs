// exit: 60

struct Point {
    x: i32,
    y: i32,
}

trait Greet {
    fn greet(&self) -> i32;
    fn greet_twice(&self) -> i32 {
        self.greet() + self.greet()
    }
}

impl Greet for Point {
    fn greet(&self) -> i32 {
        self.x + self.y
    }
}

fn main() {
    let p = Point { x: 10, y: 20 };
    exit(p.greet_twice());
}
