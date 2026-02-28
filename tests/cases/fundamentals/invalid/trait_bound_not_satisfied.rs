// error: does not implement trait

struct Point {
    x: i32,
    y: i32,
}

trait Greet {
    fn greet(&self) -> i32;
}

fn call_greet<T: Greet>(val: &T) -> i32 {
    val.greet()
}

fn main() {
    let p = Point { x: 10, y: 20 };
    call_greet(&p);
}
