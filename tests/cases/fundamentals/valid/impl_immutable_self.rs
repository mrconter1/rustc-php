// exit: 25

struct Rect {
    w: i32,
    h: i32,
}

impl Rect {
    fn area(&self) -> i32 {
        self.w * self.h
    }
}

fn main() {
    let r = Rect { w: 5, h: 5 };
    exit(r.area());
}
