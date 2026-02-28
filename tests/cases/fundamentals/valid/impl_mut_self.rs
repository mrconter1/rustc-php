// exit: 0
// stdout: 3
// stdout: 7

struct Pair {
    a: i32,
    b: i32,
}

impl Pair {
    fn sum(&self) -> i32 {
        self.a + self.b
    }

    fn swap(&mut self) {
        let tmp = self.a;
        self.a = self.b;
        self.b = tmp;
    }
}

fn main() {
    let mut p = Pair { a: 1, b: 2 };
    println!("{}", p.sum());
    p.swap();
    p.a = p.a + 2;
    p.b = p.b + 2;
    println!("{}", p.sum());
}
