// stdout: 10
// stdout: 20
// stdout: 0
// stdout: 42

struct Box<T> {
    value: T,
}

impl<T> Box<T> {
    fn new(v: T) -> Box<T> {
        Box { value: v }
    }
    fn get(&self) -> T {
        self.value
    }
}

trait Double {
    fn double(&self) -> i32;
}

impl Double for i32 {
    fn double(&self) -> i32 {
        *self * 2
    }
}

fn double_or_zero<T: Double>(b: &Box<T>) -> i32 {
    let x = b.get();
    x.double()
}

fn main() {
    let a = Box::new(5);
    println!("{}", double_or_zero(&a));
    let b = Box::new(10);
    println!("{}", double_or_zero(&b));
    let c: Option<i32> = Option::<i32>::Some(0);
    let v = match c {
        Option::<i32>::Some(n) => n,
        Option::<i32>::None => 1,
    };
    println!("{}", v);
    let d: Option<i32> = Option::<i32>::Some(21);
    let w = match d {
        Option::<i32>::Some(n) => n * 2,
        Option::<i32>::None => 0,
    };
    println!("{}", w);
}
